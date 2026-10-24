<?php

/**
 * Agent local de referință pentru casa de marcat — Another World Cluj.
 *
 * Rulează pe calculatorul de la recepție conectat la casa de marcat. Întreabă periodic serverul
 * (HTTPS, semnătură HMAC) dacă există bonuri de tipărit și raportează rezultatul.
 *
 * STATUS: adaptor pregătit. Nu a fost testat cu un aparat fiscal real. Comunicarea efectivă cu aparatul
 * depinde de modelul acestuia și se face printr-un „driver” (vezi mai jos); pentru producție este necesară
 * validarea împreună cu service-ul autorizat al casei de marcat.
 *
 * Configurare (fișier aw-fiscal-agent.ini lângă script sau variabile de mediu):
 *   AW_SERVER=https://anotherworldcluj.ro
 *   AW_AGENT_KEY=fa_...            (generat în panou: Financiar → Case de marcat → Cheie agent)
 *   AW_AGENT_SECRET=...
 *   DRIVER=file                    (file | dry-run)
 *   FILE_OUT_DIR=C:\bonuri\de-tiparit   (driver file: aici se scrie comanda JSON)
 *   FILE_IN_DIR=C:\bonuri\rezultate     (driver file: aici programul aparatului scrie {command_id}.result.json)
 *   RESULT_TIMEOUT=60              (secunde de așteptare a rezultatului)
 *   POLL_SECONDS=3
 *
 * Siguranță: înainte de a trimite o comandă la aparat, agentul o notează în jurnalul local (journal.json).
 * O comandă găsită în jurnal fără rezultat (ex. după o pană de curent) NU se retrimite: serverul o marchează
 * „incertă”, iar recepția o verifică în jurnalul electronic al aparatului. Astfel nu se emit bonuri duble.
 *
 * Pornire: php aw-fiscal-agent.php    (oprire: Ctrl+C)
 */

declare(strict_types=1);

$config = array_merge(
    is_file(__DIR__.'/aw-fiscal-agent.ini') ? (parse_ini_file(__DIR__.'/aw-fiscal-agent.ini') ?: []) : [],
    array_filter(['AW_SERVER' => getenv('AW_SERVER'), 'AW_AGENT_KEY' => getenv('AW_AGENT_KEY'), 'AW_AGENT_SECRET' => getenv('AW_AGENT_SECRET'), 'DRIVER' => getenv('DRIVER')]),
);
foreach (['AW_SERVER', 'AW_AGENT_KEY', 'AW_AGENT_SECRET'] as $required) {
    if (empty($config[$required])) {
        fwrite(STDERR, "Lipsește {$required} din configurare.\n");
        exit(1);
    }
}
$driver = $config['DRIVER'] ?? 'dry-run';
$journalPath = __DIR__.'/journal.json';
$once = in_array('--once', $argv, true);

function journal(string $path): array
{
    return is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
}

function saveJournal(string $path, array $journal): void
{
    file_put_contents($path.'.tmp', json_encode($journal, JSON_PRETTY_PRINT));
    rename($path.'.tmp', $path);
}

function request(array $config, string $method, string $path, ?array $body = null): array
{
    $payload = $body === null ? '' : json_encode($body);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp."\n".$method."\n".$path."\n".hash('sha256', $payload), $config['AW_AGENT_SECRET']);
    $ch = curl_init(rtrim($config['AW_SERVER'], '/').$path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-AW-Key: '.$config['AW_AGENT_KEY'], 'X-AW-Timestamp: '.$timestamp, 'X-AW-Signature: '.$signature],
        CURLOPT_POSTFIELDS => $method === 'GET' ? null : $payload,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false) {
        throw new RuntimeException('Rețea: '.$error);
    }

    return [$status, json_decode((string) $response, true) ?: []];
}

/** Trimite bonul la aparat. Întoarce ['status' => printed|failed, 'fiscal_number' => ?, 'error' => ?]. */
function printReceipt(string $driver, array $config, array $command): array
{
    if ($driver === 'dry-run') {
        // NU tipărește nimic: doar afișează comanda (pentru verificarea conexiunii)
        echo json_encode($command, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";

        return ['status' => 'failed', 'error' => 'Agent în mod dry-run: nu s-a tipărit nimic.'];
    }
    if ($driver === 'file') {
        $out = rtrim((string) ($config['FILE_OUT_DIR'] ?? ''), '/\\');
        $in = rtrim((string) ($config['FILE_IN_DIR'] ?? ''), '/\\');
        file_put_contents($out.DIRECTORY_SEPARATOR.$command['command_id'].'.json', json_encode($command, JSON_UNESCAPED_UNICODE));
        $deadline = time() + (int) ($config['RESULT_TIMEOUT'] ?? 60);
        $resultFile = $in.DIRECTORY_SEPARATOR.$command['command_id'].'.result.json';
        while (time() < $deadline) {
            if (is_file($resultFile)) {
                $result = json_decode((string) file_get_contents($resultFile), true) ?: [];

                return ['status' => ($result['status'] ?? '') === 'printed' ? 'printed' : 'failed', 'fiscal_number' => $result['fiscal_number'] ?? null, 'z_report_number' => $result['z_report_number'] ?? null, 'error' => $result['error'] ?? null];
            }
            usleep(500000);
        }

        throw new RuntimeException('Fără rezultat de la aparat în timpul alocat.'); // rămâne incert: NU se raportează eșec
    }

    throw new RuntimeException("Driver necunoscut: {$driver}");
}

echo "Agent pornit (driver: {$driver}).\n";
do {
    try {
        request($config, 'POST', '/api/fiscal-agent/heartbeat', ['agent_version' => '1.0', 'device_status' => $driver]);
        // rezultate tipărite dar neconfirmate de server (ex. rețea căzută după tipărire): se retrimit
        foreach (journal($journalPath) as $commandId => $entry) {
            if (isset($entry['result']) && empty($entry['reported'])) {
                [$code] = request($config, 'POST', '/api/fiscal-agent/commands/'.$commandId.'/result', $entry['result']);
                if ($code === 200 || $code === 404) {
                    $journal = journal($journalPath);
                    $journal[$commandId]['reported'] = true;
                    saveJournal($journalPath, $journal);
                }
            }
        }
        [$status, $data] = request($config, 'GET', '/api/fiscal-agent/commands');
        if ($status !== 200) {
            throw new RuntimeException("Server: HTTP {$status}");
        }
        foreach ($data['commands'] ?? [] as $command) {
            $journal = journal($journalPath);
            if (isset($journal[$command['command_id']])) {
                echo "Comanda {$command['command_id']} există deja în jurnal; nu se retipărește.\n";

                continue;
            }
            $journal[$command['command_id']] = ['started_at' => date('c')];
            saveJournal($journalPath, $journal);
            try {
                $result = printReceipt($driver, $config, $command);
            } catch (Throwable $e) {
                echo 'Rezultat incert: ', $e->getMessage(), "\n";

                continue; // fără raportare: serverul o va marca „incertă”
            }
            $journal[$command['command_id']]['result'] = $result;
            saveJournal($journalPath, $journal);
            [$code] = request($config, 'POST', '/api/fiscal-agent/commands/'.$command['command_id'].'/result', $result);
            if ($code === 200) {
                $journal = journal($journalPath);
                $journal[$command['command_id']]['reported'] = true;
                saveJournal($journalPath, $journal);
            }
            echo "Comanda {$command['command_id']}: {$result['status']}\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, date('H:i:s').' '.$e->getMessage()."\n");
    }
    if (! $once) {
        sleep((int) ($config['POLL_SECONDS'] ?? 3));
    }
} while (! $once);
