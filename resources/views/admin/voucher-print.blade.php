<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<title>Voucher {{ $voucher->code }}</title>
<style>
  @page { size: A5 landscape; margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: "Onest", system-ui, sans-serif; background: #e5e5ef; }
  .sheet { width: 210mm; height: 148mm; margin: 10mm auto; padding: 12mm 14mm; color: #fff; position: relative; overflow: hidden;
    background: radial-gradient(120% 90% at 85% 10%, #4b1f9e 0%, transparent 55%), radial-gradient(90% 80% at 0% 100%, #6d0f4f 0%, transparent 60%), #07061a; }
  .sheet::after { content: ""; position: absolute; inset: 6mm; border: 1px solid rgb(255 255 255 / .25); border-radius: 6mm; pointer-events: none; }
  header { display: flex; justify-content: space-between; align-items: center; }
  header img { height: 12mm; }
  .kicker { font-size: 9pt; letter-spacing: .04em; opacity: .8; }
  h1 { font-size: 30pt; margin: 9mm 0 2mm; line-height: 1; font-weight: 800; }
  .value { font-size: 18pt; font-weight: 700; color: #3ee6ff; }
  .to { margin-top: 5mm; font-size: 12pt; }
  .msg { margin-top: 2mm; font-size: 10pt; opacity: .9; max-width: 120mm; }
  footer { position: absolute; left: 14mm; right: 14mm; bottom: 12mm; display: flex; justify-content: space-between; align-items: flex-end; gap: 8mm; font-size: 8pt; line-height: 1.4; }
  .code { font-family: ui-monospace, monospace; font-size: 14pt; letter-spacing: .12em; font-weight: 700; }
  .qr { background: #fff; padding: 2mm; border-radius: 2mm; line-height: 0; }
  .qr svg { width: 28mm; height: 28mm; }
  .print-bar { text-align: center; margin: 6mm; font: 14px system-ui; }
  @media print { body { background: none; } .sheet { margin: 0; } .print-bar { display: none; } }
</style>
</head>
<body>
<div class="print-bar"><button onclick="window.print()">Tipărește</button></div>
<section class="sheet">
    <header>
        <img src="{{ asset('media/brand/aw-logo-horizontal.svg') }}" alt="Another World">
        <span class="kicker">Cluj-Napoca · realitate virtuală</span>
    </header>
    <h1>Voucher cadou</h1>
    <div class="value">
        @if ($voucher->type === 'package')
            {{ $voucher->package?->getTranslation('name', 'ro') }}@if ($voucher->package_participants) · {{ $voucher->package_participants }} persoane @endif
        @else
            {{ money($voucher->initial_value_minor) }}
        @endif
    </div>
    @if ($voucher->recipient_name)<div class="to">Pentru: <strong>{{ $voucher->recipient_name }}</strong></div>@endif
    @if ($voucher->message)<div class="msg">{{ $voucher->message }}</div>@endif
    <footer>
        <div>
            <div class="code">{{ $voucher->code }}</div>
            <div>Valabil {{ $voucher->valid_from ? 'de la '.$voucher->valid_from->format('d.m.Y') : '' }}{{ $voucher->valid_until ? ' până la '.$voucher->valid_until->format('d.m.Y') : '' }}.</div>
            <div>Rezervare: {{ preg_replace('#^https?://#', '', rtrim(config('app.url'), '/')) }}@if (filled_setting('contact.phone_bookings') ?? filled_setting('contact.phone_main')) · {{ filled_setting('contact.phone_bookings') ?? filled_setting('contact.phone_main') }}@endif</div>
            @if ($terms)<div>Se aplică „{{ $terms->getTranslation('title', 'ro') }}” v{{ $terms->version }}.</div>@endif
        </div>
        <div class="qr">{!! $qr !!}</div>
    </footer>
</section>
</body>
</html>
