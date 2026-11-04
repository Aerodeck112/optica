<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<title>Cod QR — declarația de participare</title>
<style>
  @page { size: A4; margin: 12mm; }
  body { font-family: system-ui, sans-serif; color: #0a1033; text-align: center; margin: 0; }
  .sheet { max-width: 180mm; margin: 8mm auto; }
  h1 { font-size: 26pt; margin: 6mm 0 2mm; } p { font-size: 13pt; margin: 1mm 0; }
  .codes { display: flex; gap: 10mm; justify-content: center; margin-top: 8mm; }
  .code svg { width: 75mm; height: 75mm; } .code p { font-size: 15pt; font-weight: 700; }
  .url { font-size: 9pt; color: #555; word-break: break-all; }
  .print-bar { margin: 6mm; } @media print { .print-bar { display: none; } }
</style>
</head>
<body>
<div class="print-bar"><button onclick="window.print()">Tipărește</button></div>
<div class="sheet">
    <img src="{{ asset('media/brand/aw-logo-stacked-cluj-dark.svg') }}" alt="Another World Cluj" style="height:32mm">
    <h1>Declarația de participare</h1>
    <p>Înainte de joc, scanează codul și completează declarația. Pentru minori, o completează părintele.</p>
    <p><em>Before playing, scan the code and complete the participation agreement. A parent completes it for children.</em></p>
    <div class="codes">
        <div class="code">{!! $qr['ro'] !!}<p>Română</p><p class="url">{{ $urls['ro'] }}</p></div>
        <div class="code">{!! $qr['en'] !!}<p>English</p><p class="url">{{ $urls['en'] }}</p></div>
    </div>
</div>
</body>
</html>
