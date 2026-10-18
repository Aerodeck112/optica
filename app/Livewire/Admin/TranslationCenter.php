<?php

namespace App\Livewire\Admin;

use App\Domain\Import\TableReader;
use App\Models\MessageTemplate;
use App\Models\TranslationOverride;
use App\Support\Audit\AuditLogger;
use App\Support\Translation\TranslationCatalog;
use App\Support\Translation\TranslationOverrides;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Translation centre (TRD-03…TRD-05): every interface text in RO and HU, searchable by text, key,
 * module and page; missing, draft and corrected texts; import/export for review outside the
 * application. Imported texts are drafts; only a publisher makes them live.
 */
class TranslationCenter extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Url]
    public string $q = '';

    #[Url]
    public string $group = '';

    /** Route name of a page where the text was seen in translation mode. */
    #[Url(as: 'screen')]
    public string $screen = '';

    #[Url]
    public string $status = '';

    public $upload = null;

    public ?array $importResult = null;

    public ?string $notice = null;

    public function boot(): void
    {
        $this->authorize('translations.edit');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['q', 'group', 'screen', 'status'], true)) {
            $this->resetPage();
        }
    }

    #[On('tr-saved')]
    public function refreshList(): void {}

    public function edit(string $key): void
    {
        abort_unless(app(TranslationCatalog::class)->exists($key), 404);
        $this->dispatch('tr-open', keys: [$key]);
    }

    public function publishDrafts(TranslationOverrides $overrides): void
    {
        $this->authorize('translations.publish');
        $published = 0;
        $failed = [];
        $drafts = TranslationOverride::whereNotNull('draft_value')->get()->filter(fn ($o) => $o->draft_value !== $o->published_value);
        foreach ($drafts as $o) {
            try {
                $overrides->publish($o->locale, $o->key, auth()->user());
                $published++;
            } catch (ValidationException) {
                $failed[] = $o->locale.' · '.$o->key;
            }
        }
        app(AuditLogger::class)->log('translations.published_drafts', null, ['count' => $published, 'failed' => count($failed)]);
        $this->notice = trans_choice('translations.center.published_count', $published, ['count' => $published])
            .($failed ? ' '.__('translations.center.publish_failed', ['keys' => implode(', ', array_slice($failed, 0, 5))]) : '');
    }

    // ----- export / import --------------------------------------------------------------------

    public function export(string $format): StreamedResponse
    {
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 422);
        $rows = $this->filteredRows();
        $locales = config('optica.locales');
        $headers = ['key', 'module', 'status'];
        foreach ($locales as $l) {
            array_push($headers, $l.'_standard', $l);
        }
        $lines = array_map(function ($r) use ($locales) {
            $line = [$r['key'], $r['group'], implode(',', array_filter(TranslationCatalog::STATUSES, fn ($s) => $r[$s]))];
            foreach ($locales as $l) {
                $loc = $r['locales'][$l];
                array_push($line, (string) $loc['default'], (string) ($loc['draft'] ?? $loc['published'] ?? $loc['default']));
            }

            return array_map(fn ($v) => self::safeCell($v), $line);
        }, $rows);
        $name = 'traduceri-'.now(config('optica.clinic_timezone'))->format('Ymd-Hi').'.'.$format;

        return response()->streamDownload(function () use ($format, $headers, $lines) {
            if ($format === 'csv') {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $headers, ';');
                foreach ($lines as $line) {
                    fputcsv($out, $line, ';');
                }
                fclose($out);

                return;
            }
            $writer = new XlsxWriter;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues($headers));
            foreach ($lines as $line) {
                $writer->addRow(Row::fromValues($line));
            }
            $writer->close();
        }, $name, ['Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** Text that starts like a formula is prefixed, so a spreadsheet never executes it. */
    private static function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private static function unsafeCell(string $value): string
    {
        return preg_match("/^'[=+\\-@\t\r]/", $value) ? substr($value, 1) : $value;
    }

    public function import(TranslationOverrides $overrides, TranslationCatalog $catalog): void
    {
        $this->validate(['upload' => ['required', 'file', 'max:5120', 'mimes:csv,txt,xlsx']], [], ['upload' => __('translations.center.file')]);
        try {
            $table = $this->readTable($this->upload->getRealPath(), strtolower($this->upload->getClientOriginalExtension()));
        } catch (Throwable) {
            $table = null;
        }
        $header = $table ? array_map(fn ($h) => mb_strtolower(trim((string) $h)), array_shift($table)) : [];
        $keyCol = array_search('key', $header, true);
        if ($keyCol === false) {
            $this->addError('upload', __('translations.center.bad_file'));

            return;
        }
        $localeCols = [];
        foreach (config('optica.locales') as $l) {
            if (($c = array_search($l, $header, true)) !== false) {
                $localeCols[$l] = $c;
            }
        }
        $result = ['drafts' => 0, 'unchanged' => 0, 'errors' => []];
        $current = TranslationOverride::all()->groupBy('key');
        foreach ($table as $i => $row) {
            $key = trim((string) ($row[$keyCol] ?? ''));
            if ($key === '') {
                continue;
            }
            if (! $catalog->exists($key)) {
                $result['errors'][] = __('translations.center.row_error', ['row' => $i + 2, 'error' => __('translations.center.unknown_key', ['key' => $key])]);

                continue;
            }
            $standard = $catalog->keys()[$key]['default'];
            foreach ($localeCols as $locale => $col) {
                $value = trim(self::unsafeCell((string) ($row[$col] ?? '')));
                $o = $current->get($key)?->firstWhere('locale', $locale);
                $effective = $o?->draft_value ?? $o?->published_value ?? $standard[$locale];
                if ($value === '' || $value === $effective) {
                    $result['unchanged']++;

                    continue;
                }
                try {
                    $overrides->saveDraft($locale, $key, $value, auth()->user(), 'import');
                    $result['drafts']++;
                } catch (ValidationException $e) {
                    $result['errors'][] = __('translations.center.row_error', ['row' => $i + 2, 'error' => strtoupper($locale).' '.$key.': '.collect($e->errors())->flatten()->first()]);
                }
            }
        }
        app(AuditLogger::class)->log('translations.imported', null, ['drafts' => $result['drafts'], 'errors' => count($result['errors'])]);
        $result['errors'] = array_slice($result['errors'], 0, 30);
        $this->importResult = $result;
        $this->upload = null;
    }

    /** @return list<list<string>> */
    private function readTable(string $path, string $extension): array
    {
        return iterator_to_array(TableReader::rows($path, $extension, 20000), false);
    }

    // ----- data -------------------------------------------------------------------------------

    private function filteredRows(): array
    {
        $catalog = app(TranslationCatalog::class);
        $keys = null;
        if ($this->screen !== '') {
            $keys = DB::table('translation_usages')->where('route', $this->screen)->pluck('key')->all();
        }

        return $catalog->rows([
            'q' => $this->q,
            'group' => in_array($this->group, $catalog->groups(), true) ? $this->group : '',
            'status' => in_array($this->status, TranslationCatalog::STATUSES, true) ? $this->status : '',
            'keys' => $keys,
        ]);
    }

    /** Message templates exist per language; a type and channel with only one language is listed (TRD-05). */
    private function templateGaps(): array
    {
        $locales = config('optica.locales');
        $gaps = [];
        foreach (MessageTemplate::where('is_active', true)->get(['type', 'channel', 'locale', 'location_id'])->groupBy(fn ($t) => $t->type.'|'.$t->channel.'|'.$t->location_id) as $set) {
            $missing = array_values(array_diff($locales, $set->pluck('locale')->all()));
            if ($missing) {
                $gaps[] = ['type' => $set[0]->type, 'channel' => $set[0]->channel, 'missing' => $missing];
            }
        }

        return $gaps;
    }

    public function render(TranslationCatalog $catalog)
    {
        $rows = $this->filteredRows();
        $perPage = 25;
        $page = max(1, (int) $this->getPage());
        $paginator = new LengthAwarePaginator(array_slice($rows, ($page - 1) * $perPage, $perPage), count($rows), $perPage, $page);
        $all = $catalog->rows();

        return view('livewire.admin.translation-center', [
            'rows' => $paginator,
            'groups' => $catalog->groups(),
            'routes' => DB::table('translation_usages')->distinct()->orderBy('route')->pluck('route'),
            'counts' => [
                'total' => count($all),
                'missing' => count(array_filter($all, fn ($r) => $r['missing'])),
                'draft' => count(array_filter($all, fn ($r) => $r['draft'])),
                'modified' => count(array_filter($all, fn ($r) => $r['modified'])),
            ],
            'templateCount' => MessageTemplate::count(),
            'templateGaps' => $this->templateGaps(),
        ])->layout('components.layouts.crm')->title(__('translations.center.title'));
    }
}
