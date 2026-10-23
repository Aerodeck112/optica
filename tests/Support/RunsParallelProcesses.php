<?php

namespace Tests\Support;

/**
 * Pornește procese PHP reale (conexiuni separate la baza de date) care execută aceeași operație simultan.
 */
trait RunsParallelProcesses
{
    /**
     * @param  array<int, array{0:string,1:int,2:string,3:string,4:string}>  $jobs  [operație, id, argument, sursă, etichetă]
     * @return array<int, array>
     */
    protected function runInParallel(array $jobs, float $delaySeconds = 2.5): array
    {
        $barrier = microtime(true) + $delaySeconds;
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => config('database.default'),
            'DB_DATABASE' => config('database.connections.'.config('database.default').'.database'),
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'AW_MESSAGING_SANDBOX' => 'true',
        ]);

        $processes = [];
        foreach ($jobs as $i => [$operation, $id, $argument, $source, $label]) {
            $command = [PHP_BINARY, base_path('tests/Support/concurrent_booking.php'), $operation, (string) $id, $argument, sprintf('%.6f', $barrier), $source, $label];
            $pipes = [];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $processes[$i] = [$process, $pipes];
        }

        $results = [];
        foreach ($processes as $i => [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $decoded = json_decode(trim($stdout), true);
            $results[$i] = is_array($decoded) ? $decoded : ['ok' => false, 'reason' => 'crash', 'stdout' => $stdout, 'stderr' => mb_substr($stderr, 0, 2000)];
        }

        return $results;
    }
}
