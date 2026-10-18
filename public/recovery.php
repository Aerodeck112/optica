<?php

/*
 | Recovery after an interrupted update (UPD-06). Works without Laravel: it only needs PHP,
 | the .env database settings and the recovery token shown at installation (or regenerated in
 | Sistem → Actualizări). It can roll back the last update from its backup and leave
 | maintenance mode. If the application files live outside public_html, set $base below.
 */

declare(strict_types=1);

$base = dirname(__DIR__);

require $base.'/app/Support/Recovery/RecoveryKit.php';

use App\Support\Recovery\RecoveryKit;

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Content-Type: text/html; charset=utf-8');

$kit = new RecoveryKit($base);
$lang = ($_GET['lang'] ?? $_POST['lang'] ?? 'ro') === 'hu' ? 'hu' : 'ro';
$t = [
    'ro' => [
        'title' => 'Recuperare Optica Optofarm',
        'intro' => 'Această pagină repune aplicația în starea dinaintea unei actualizări întrerupte. Folosiți tokenul de recuperare afișat la instalare sau regenerat în Sistem → Actualizări.',
        'token' => 'Token de recuperare',
        'show' => 'Afișează starea',
        'bad_token' => 'Token greșit.',
        'locked' => 'Prea multe încercări. Reîncercați peste 15 minute.',
        'no_update' => 'Nu există o actualizare întreruptă.',
        'state' => 'Starea ultimei actualizări',
        'status' => 'Status',
        'step' => 'Pas',
        'versions' => 'Versiuni',
        'maintenance' => 'Mod mentenanță',
        'on' => 'activ',
        'off' => 'inactiv',
        'rollback' => 'Restaurează starea dinaintea actualizării',
        'rollback_help' => 'Fișierele înlocuite sunt puse înapoi, fișierele noi sunt șterse, iar baza de date este restaurată din backupul verificat făcut înaintea actualizării (dacă actualizarea ajunsese la migrări). În mentenanță nu s-au putut introduce date noi.',
        'confirm' => 'Confirm restaurarea',
        'maintenance_off' => 'Ieși din modul mentenanță',
        'done_rollback' => 'Restaurarea s-a încheiat. Aplicația rulează versiunea anterioară.',
        'done_maintenance' => 'Modul mentenanță a fost oprit.',
        'error' => 'Eroare',
        'log' => 'Jurnal',
        'no_hash' => 'Nu există un token de recuperare pe acest server. Generați-l din Sistem → Actualizări sau cu „php artisan optica:recovery-token”.',
    ],
    'hu' => [
        'title' => 'Optica Optofarm helyreállítás',
        'intro' => 'Ez az oldal visszaállítja az alkalmazást egy megszakadt frissítés előtti állapotba. Használja a telepítéskor kapott vagy a Rendszer → Frissítések menüben újragenerált helyreállítási tokent.',
        'token' => 'Helyreállítási token',
        'show' => 'Állapot megjelenítése',
        'bad_token' => 'Hibás token.',
        'locked' => 'Túl sok próbálkozás. Próbálja újra 15 perc múlva.',
        'no_update' => 'Nincs megszakadt frissítés.',
        'state' => 'Az utolsó frissítés állapota',
        'status' => 'Állapot',
        'step' => 'Lépés',
        'versions' => 'Verziók',
        'maintenance' => 'Karbantartási mód',
        'on' => 'aktív',
        'off' => 'inaktív',
        'rollback' => 'A frissítés előtti állapot visszaállítása',
        'rollback_help' => 'A lecserélt fájlok visszakerülnek, az új fájlok törlődnek, és az adatbázis a frissítés előtt készült, ellenőrzött biztonsági mentésből áll vissza (ha a frissítés elérte a migrációkat). Karbantartás alatt nem lehetett új adatot rögzíteni.',
        'confirm' => 'Megerősítem a visszaállítást',
        'maintenance_off' => 'Kilépés a karbantartási módból',
        'done_rollback' => 'A visszaállítás befejeződött. Az alkalmazás az előző verziót futtatja.',
        'done_maintenance' => 'A karbantartási mód kikapcsolva.',
        'error' => 'Hiba',
        'log' => 'Napló',
        'no_hash' => 'Ezen a szerveren nincs helyreállítási token. Hozza létre a Rendszer → Frissítések menüben vagy a „php artisan optica:recovery-token” paranccsal.',
    ],
][$lang];
$h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// Failed attempts per IP: 5 per 15 minutes.
$attemptsFile = $kit->path('storage/app/private/recovery-attempts.json');
$ip = substr(hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 16);
$attempts = is_file($attemptsFile) ? (json_decode((string) file_get_contents($attemptsFile), true) ?: []) : [];
$attempts[$ip] = array_values(array_filter($attempts[$ip] ?? [], fn ($at) => $at > time() - 900));

$message = null;
$error = null;
$authorised = false;
$token = (string) ($_POST['token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (count($attempts[$ip]) >= 5) {
        $error = $t['locked'];
    } elseif (! $kit->checkRecoveryToken($token)) {
        $attempts[$ip][] = time();
        @file_put_contents($attemptsFile, json_encode($attempts));
        $error = $t['bad_token'];
    } else {
        $authorised = true;
        $action = $_POST['action'] ?? 'status';
        try {
            if ($action === 'rollback' && ($_POST['confirm'] ?? '') === '1') {
                if (! $kit->interrupted()) {
                    $error = $t['no_update'];
                } else {
                    $kit->rollback();
                    $message = $t['done_rollback'];
                }
            } elseif ($action === 'maintenance_off') {
                $kit->maintenanceOff();
                $message = $t['done_maintenance'];
            }
        } catch (Throwable $e) {
            $error = $t['error'].': '.$e->getMessage();
        }
    }
}
$journal = $authorised ? $kit->journal() : null;
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $h($t['title']) ?></title>
    <style>
        body { font: 16px/1.5 system-ui, sans-serif; margin: 0; background: #f4f2ee; color: #1b2a3a; }
        main { max-width: 40rem; margin: 0 auto; padding: 2rem 1rem; }
        h1 { font-size: 1.5rem; } h2 { font-size: 1.1rem; margin-top: 2rem; }
        .panel { background: #fff; border: 1px solid #ddd8cf; border-radius: .75rem; padding: 1rem 1.25rem; margin-top: 1rem; }
        label { display: block; font-weight: 600; margin-bottom: .25rem; }
        input[type=password] { width: 100%; box-sizing: border-box; padding: .6rem; font-size: 1rem; border: 1px solid #b9b2a6; border-radius: .5rem; }
        button { min-height: 44px; padding: 0 1rem; font-size: 1rem; border-radius: .5rem; border: 1px solid #0b3c5d; background: #0b3c5d; color: #fff; cursor: pointer; margin-top: .75rem; }
        button.secondary { background: #fff; color: #0b3c5d; }
        .ok { background: #e6f4ea; color: #1e6b34; padding: .75rem 1rem; border-radius: .5rem; }
        .bad { background: #fdecea; color: #a12a1f; padding: .75rem 1rem; border-radius: .5rem; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: .25rem 1rem; } dt { color: #5f6b76; }
        ol { font-size: .85rem; color: #5f6b76; padding-left: 1.25rem; }
        a { color: #0b3c5d; }
    </style>
</head>
<body>
<main>
    <p><a href="?lang=ro" lang="ro">Română</a> · <a href="?lang=hu" lang="hu">Magyar</a></p>
    <h1><?= $h($t['title']) ?></h1>
    <p><?= $h($t['intro']) ?></p>
    <?php if (! is_file($kit->recoveryHashPath())) { ?><p class="bad"><?= $h($t['no_hash']) ?></p><?php } ?>
    <?php if ($message) { ?><p class="ok" role="status"><?= $h($message) ?></p><?php } ?>
    <?php if ($error) { ?><p class="bad" role="alert"><?= $h($error) ?></p><?php } ?>

    <form method="post" class="panel">
        <input type="hidden" name="lang" value="<?= $lang ?>">
        <input type="hidden" name="action" value="status">
        <label for="token"><?= $h($t['token']) ?></label>
        <input id="token" name="token" type="password" autocomplete="off" required value="<?= $authorised ? $h($token) : '' ?>">
        <button type="submit"><?= $h($t['show']) ?></button>
    </form>

    <?php if ($authorised) { ?>
        <section class="panel">
            <h2><?= $h($t['state']) ?></h2>
            <?php if (! $journal) { ?>
                <p><?= $h($t['no_update']) ?></p>
            <?php } else { ?>
                <dl>
                    <dt><?= $h($t['status']) ?></dt><dd><?= $h($journal['status'] ?? '—') ?></dd>
                    <dt><?= $h($t['step']) ?></dt><dd><?= $h($journal['step'] ?? '—') ?></dd>
                    <dt><?= $h($t['versions']) ?></dt><dd><?= $h(($journal['from_version'] ?? '?').' → '.($journal['to_version'] ?? '?')) ?></dd>
                    <dt><?= $h($t['maintenance']) ?></dt><dd><?= $kit->inMaintenance() ? $h($t['on']) : $h($t['off']) ?></dd>
                </dl>
                <h2><?= $h($t['log']) ?></h2>
                <ol>
                    <?php foreach (array_slice($journal['log'] ?? [], -12) as $entry) { ?>
                        <li><?= $h(($entry['at'] ?? '').' · '.($entry['level'] ?? '').' · '.($entry['message'] ?? '')) ?></li>
                    <?php } ?>
                </ol>
            <?php } ?>

            <?php if ($kit->interrupted()) { ?>
                <form method="post">
                    <input type="hidden" name="lang" value="<?= $lang ?>">
                    <input type="hidden" name="token" value="<?= $h($token) ?>">
                    <input type="hidden" name="action" value="rollback">
                    <p><?= $h($t['rollback_help']) ?></p>
                    <label><input type="checkbox" name="confirm" value="1" required> <?= $h($t['confirm']) ?></label>
                    <button type="submit"><?= $h($t['rollback']) ?></button>
                </form>
            <?php } ?>
            <?php if ($kit->inMaintenance()) { ?>
                <form method="post">
                    <input type="hidden" name="lang" value="<?= $lang ?>">
                    <input type="hidden" name="token" value="<?= $h($token) ?>">
                    <input type="hidden" name="action" value="maintenance_off">
                    <button type="submit" class="secondary"><?= $h($t['maintenance_off']) ?></button>
                </form>
            <?php } ?>
        </section>
    <?php } ?>
</main>
</body>
</html>
