<?php

namespace App\Domain\Import;

use App\Models\Appointment;
use App\Models\ImportRun;
use App\Models\Patient;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileObject;
use Throwable;

/**
 * Import of exported data (MIG-01…04): upload → column mapping → preview → simulation without
 * writing → import in batches. Each batch and its cursor are committed together, so an
 * interrupted import resumes exactly where it stopped, without duplicates. The original file
 * stays private next to the run, for the retention period agreed with the customer.
 */
class ImportRunner
{
    public const TARGETS = ['patients' => PatientImport::class, 'appointments' => AppointmentImport::class];

    public const OUTCOMES = ['created', 'updated', 'linked', 'unchanged', 'duplicate', 'error'];

    private const MAX_ISSUES = 2000;

    public static function target(string $target): ImportTarget
    {
        $class = self::TARGETS[$target] ?? throw new RuntimeException('Unknown import target');

        return new $class;
    }

    public function upload(string $path, string $originalName, string $target, string $source, ?User $user): ImportRun
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $uuid = (string) Str::uuid();
        $dir = storage_path('app/private/imports/'.$uuid);
        mkdir($dir, 0750, true);
        copy($path, $dir.'/source.'.$extension);

        $headers = null;
        $count = 0;
        $out = new SplFileObject($dir.'/rows.jsonl', 'w');
        foreach (TableReader::rows($dir.'/source.'.$extension, $extension) as $row) {
            if ($headers === null) {
                if (implode('', $row) === '') {
                    continue;
                }
                $headers = array_map(fn ($h) => mb_substr(trim($h), 0, 80), $row);

                continue;
            }
            if (trim(implode('', $row)) === '') {
                continue;
            }
            $out->fwrite(json_encode($row, JSON_UNESCAPED_UNICODE)."\n");
            $count++;
        }
        $out = null;
        if ($headers === null) {
            throw new RuntimeException('empty');
        }

        return ImportRun::create([
            'uuid' => $uuid, 'target' => $target, 'source_label' => Str::slug($source) ?: 'import', 'original_name' => mb_substr($originalName, 0, 190),
            'file_path' => 'imports/'.$uuid, 'headers' => $headers, 'mapping' => self::target($target)->guessMapping($headers),
            'total_rows' => $count, 'created_by' => $user?->id,
        ]);
    }

    /** Required fields that the mapping does not cover. */
    public function missingFields(ImportRun $run): array
    {
        $mapped = array_values($run->mapping ?? []);
        $missing = [];
        foreach (self::target($run->target)->fields() as $field => $meta) {
            if (! empty($meta['required']) && ! in_array($field, $mapped, true)) {
                $missing[] = $field;
            }
        }
        if ($run->target === 'patients' && ! in_array('full_name', $mapped, true) && (! in_array('last_name', $mapped, true) || ! in_array('first_name', $mapped, true))) {
            $missing[] = 'last_name';
        }

        return $missing;
    }

    /** @return \Generator<int, array{0: int, 1: array<string, string>}> data row number (1-based) → mapped values */
    private function rows(ImportRun $run, int $from = 0, ?int $limit = null): \Generator
    {
        $file = new SplFileObject(storage_path('app/private/'.$run->file_path.'/rows.jsonl'));
        $file->seek($from);
        $read = 0;
        while (! $file->eof() && ($limit === null || $read < $limit)) {
            $line = $file->current();
            $file->next();
            if (trim((string) $line) === '') {
                continue;
            }
            $cells = json_decode((string) $line, true) ?: [];
            $values = [];
            foreach ($run->mapping ?? [] as $index => $field) {
                if ($field) {
                    $values[$field] = (string) ($cells[(int) $index] ?? '');
                }
            }
            $read++;
            yield [$from + $read, $values];
        }
    }

    private function context(ImportRun $run, array $options = []): ImportContext
    {
        return new ImportContext($run->source_label, $options + ($run->stats['options'] ?? []), $run->created_by);
    }

    /** First rows with what would happen to them. */
    public function preview(ImportRun $run, int $count = 15): array
    {
        $target = self::target($run->target);
        $context = $this->context($run);
        $result = [];
        foreach ($this->rows($run, 0, $count) as [$number, $values]) {
            $result[] = ['row' => $number, 'values' => $values] + $target->process($values, $context, true);
        }

        return $result;
    }

    /** The whole file, without writing anything (MIG-02). */
    public function simulate(ImportRun $run): array
    {
        $target = self::target($run->target);
        $context = $this->context($run);
        $stats = array_fill_keys(self::OUTCOMES, 0);
        $issues = [];
        foreach ($this->rows($run) as [$number, $values]) {
            $r = $target->process($values, $context, true);
            $stats[$r['outcome']]++;
            if (($r['outcome'] !== 'created' || $r['messages']) && count($issues) < self::MAX_ISSUES) {
                $issues[] = ['row' => $number, 'outcome' => $r['outcome'], 'messages' => $r['messages']];
            }
        }
        $run->update(['simulation' => ['stats' => $stats, 'at' => now()->toIso8601String()], 'issues' => $issues]);

        return $stats;
    }

    public function start(ImportRun $run, array $options = []): void
    {
        if (in_array($run->status, ['running', 'done'], true)) {
            return;
        }
        // Rows that repeat an earlier identifier of the file, known before the batches split it.
        $seen = [];
        $repeats = [];
        foreach ($this->rows($run) as [$number, $values]) {
            $ref = trim((string) ($values['external_ref'] ?? ''));
            if ($ref !== '' && isset($seen[$ref])) {
                $repeats[] = $number;
            }
            $seen[$ref] = true;
        }
        $run->update(['status' => 'running', 'cursor' => 0, 'issues' => [], 'stats' => ['options' => $options, 'repeats' => $repeats] + array_fill_keys(self::OUTCOMES, 0)]);
        app(AuditLogger::class)->log('import.started', $run, ['target' => $run->target, 'rows' => $run->total_rows]);
    }

    /** One batch; the rows and the new cursor are committed together. */
    public function runBatch(ImportRun $run, int $size = 200): ImportRun
    {
        if ($run->status !== 'running') {
            return $run;
        }
        $target = self::target($run->target);
        try {
            DB::transaction(function () use ($run, $target, $size) {
                $locked = ImportRun::lockForUpdate()->find($run->id);
                if ($locked->status !== 'running') {
                    return;
                }
                $context = $this->context($locked);
                $stats = $locked->stats;
                $issues = $locked->issues ?? [];
                $processed = 0;
                $repeats = array_flip($stats['repeats'] ?? []);
                foreach ($this->rows($locked, $locked->cursor, $size) as [$number, $values]) {
                    try {
                        $r = isset($repeats[$number])
                            ? ['outcome' => 'duplicate', 'messages' => ['repeated_in_file']]
                            : DB::transaction(fn () => $target->process($values, $context, false));
                    } catch (Throwable $e) {
                        $r = ['outcome' => 'error', 'messages' => ['exception:'.class_basename($e)]];
                    }
                    $stats[$r['outcome']]++;
                    if (($r['outcome'] !== 'created' || $r['messages']) && count($issues) < self::MAX_ISSUES) {
                        $issues[] = ['row' => $number, 'outcome' => $r['outcome'], 'messages' => $r['messages']];
                    }
                    $processed++;
                }
                $cursor = $locked->cursor + $processed;
                $done = $processed === 0 || $cursor >= $locked->total_rows;
                $locked->update(['cursor' => $cursor, 'stats' => $stats, 'issues' => $issues] + ($done ? ['status' => 'done', 'finished_at' => now()] : []));
                if ($done) {
                    app(AuditLogger::class)->log('import.finished', $locked, ['target' => $locked->target, 'stats' => array_intersect_key($stats, array_flip(self::OUTCOMES))]);
                }
            });
        } catch (Throwable $e) {
            $run->refresh()->update(['status' => 'failed']);
            report($e);
        }

        return $run->refresh();
    }

    /** A run stopped by an error continues from its cursor. */
    public function resume(ImportRun $run): void
    {
        if ($run->status === 'failed') {
            $run->update(['status' => 'running']);
        }
    }

    /**
     * Reconciliation (MIG-04): every row of the file is accounted for, and the records carrying
     * this source's references are counted in the database.
     */
    public function reconciliation(ImportRun $run): array
    {
        $stats = array_intersect_key($run->stats ?? [], array_flip(self::OUTCOMES));
        $model = $run->target === 'patients' ? Patient::class : Appointment::class;

        return [
            'rows' => $run->total_rows,
            'accounted' => array_sum($stats),
            'stats' => $stats,
            'in_database' => $model::where('external_ref', 'like', $run->source_label.':%')->count(),
        ];
    }
}
