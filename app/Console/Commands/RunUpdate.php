<?php

namespace App\Console\Commands;

use App\Domain\System\UpdateRunner;
use Illuminate\Console\Command;

/** The same update flow as Sistem → Actualizări, for hosts where the console is available. */
class RunUpdate extends Command
{
    protected $signature = 'optica:update {package? : path to the update ZIP} {--resume : continue an interrupted update} {--rollback : restore the state before an interrupted update}';

    protected $description = 'Verifies and applies a signed update package';

    public function handle(): int
    {
        $runner = UpdateRunner::make();
        if ($this->option('rollback')) {
            $journal = $runner->rollback(null);
            $this->info('Status: '.$journal['status']);

            return self::SUCCESS;
        }
        if (! $this->option('resume')) {
            $path = (string) $this->argument('package');
            if (! is_file($path)) {
                $this->error('Package not found.');

                return self::FAILURE;
            }
            $copy = tempnam(sys_get_temp_dir(), 'upd');
            copy($path, $copy);
            $result = $runner->prepare($copy, basename($path), null);
            if (! $result['ok']) {
                foreach ($result['errors'] as $error) {
                    $this->error($error);
                }

                return self::FAILURE;
            }
            $runner->start(null);
        }
        do {
            $journal = $runner->advance();
            $this->line(sprintf('[%s] %s', $journal['step'] ?? '-', end($journal['log'])['message'] ?? ''));
        } while (($journal['status'] ?? null) === 'running');
        $this->info('Status: '.$journal['status']);

        return $journal['status'] === 'done' ? self::SUCCESS : self::FAILURE;
    }
}
