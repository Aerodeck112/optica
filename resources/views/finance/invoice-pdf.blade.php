@php
    /** @var \App\Modules\Finance\Models\Invoice $invoice */
    $s = (array) $invoice->seller_snapshot;
    $b = (array) $invoice->buyer_snapshot;
    $title = match ($invoice->document_type) { 'credit_note' => 'FACTURĂ DE STORNARE', 'proforma' => 'FACTURĂ PROFORMĂ', default => $invoice->kind === 'advance' ? 'FACTURĂ DE AVANS' : 'FACTURĂ' };
    $m = fn ($v) => \App\Support\Money::format((int) $v, 'RON', false);
@endphp
<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 18mm 14mm; }
  body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #14102e; }
  h1 { font-size: 16pt; margin: 0 0 2mm; letter-spacing: .02em; }
  .meta td { padding: 0 6mm 0 0; }
  .parties { width: 100%; margin: 6mm 0; border-collapse: collapse; }
  .parties td { width: 50%; vertical-align: top; padding: 3mm; border: 1px solid #d6d3e6; }
  .label { font-size: 7.5pt; text-transform: uppercase; color: #6b6785; letter-spacing: .05em; }
  table.lines { width: 100%; border-collapse: collapse; margin-top: 4mm; }
  table.lines th { background: #14102e; color: #fff; font-weight: normal; font-size: 8pt; padding: 2mm; text-align: left; }
  table.lines td { border-bottom: 1px solid #e3e1ee; padding: 2mm; }
  .num { text-align: right; white-space: nowrap; }
  .totals { width: 55%; margin-left: 45%; border-collapse: collapse; margin-top: 4mm; }
  .totals td { padding: 1.5mm 2mm; }
  .totals tr.grand td { font-size: 11pt; font-weight: bold; border-top: 2px solid #14102e; }
  .notes { margin-top: 8mm; font-size: 8pt; color: #3c3858; }
  .brand { color: #e61c4a; font-weight: bold; }
</style>
</head>
<body>
<table style="width:100%"><tr>
    <td><h1>{{ $title }}</h1>
        <table class="meta"><tr><td><span class="label">Nr.</span><br><strong>{{ $invoice->full_number ?? 'CIORNĂ' }}</strong></td>
            <td><span class="label">Data emiterii</span><br>{{ $invoice->issue_date?->format('d.m.Y') ?? '—' }}</td>
            <td><span class="label">Scadență</span><br>{{ $invoice->due_date?->format('d.m.Y') ?? '—' }}</td>
            <td><span class="label">Monedă</span><br>{{ $invoice->currency }}</td></tr></table>
    </td>
    <td style="text-align:right;vertical-align:top"><span class="brand">ANOTHER WORLD</span><br>Cluj-Napoca</td>
</tr></table>

<table class="parties"><tr>
    <td><div class="label">Furnizor</div>
        <strong>{{ $s['name'] ?? '' }}</strong><br>
        CUI: {{ $s['tax_id'] ?? '' }} · Reg. Com.: {{ $s['reg_no'] ?? '' }}<br>
        {{ $s['address'] ?? '' }}<br>
        @if (! empty($s['share_capital']))Capital social: {{ $s['share_capital'] }}<br>@endif
        @if (! empty($s['iban']))IBAN: {{ $s['iban'] }}@if (! empty($s['bank'])) · {{ $s['bank'] }}@endif<br>@endif
        @if (! empty($s['vat_on_collection']))<strong>TVA la încasare</strong><br>@endif
    </td>
    <td><div class="label">Cumpărător</div>
        <strong>{{ $b['name'] ?? '' }}</strong><br>
        @if (! empty($b['tax_id']))CUI: {{ $b['tax_id'] }}@if (! empty($b['reg_no'])) · Reg. Com.: {{ $b['reg_no'] }}@endif<br>@endif
        {{ trim(implode(', ', array_filter([$b['address'] ?? null, $b['city'] ?? null, $b['county'] ?? null]))) }}
    </td>
</tr></table>

<table class="lines">
    <thead><tr><th>#</th><th>Denumire</th><th class="num">Cant.</th><th class="num">Preț unitar fără TVA</th><th class="num">Cotă TVA</th><th class="num">Valoare fără TVA</th></tr></thead>
    <tbody>
    @foreach ($invoice->lines as $line)
        <tr>
            <td>{{ $line->position }}</td>
            <td>{{ $line->description }}</td>
            <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 3, ',', '.'), '0'), ',') }}</td>
            <td class="num">{{ number_format((float) $line->unit_price, 2, ',', '.') }}</td>
            <td class="num">{{ $line->vat_category === 'S' ? rtrim(rtrim(number_format((float) $line->vat_rate, 2, ',', ''), '0'), ',').'%' : $line->vat_category }}</td>
            <td class="num">{{ $m($line->line_net_minor) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    @foreach ((array) $invoice->vat_breakdown as $row)
        <tr><td>Bază {{ $row['category'] === 'S' ? rtrim(rtrim($row['rate'], '0'), '.').'%' : $row['category'] }}</td><td class="num">{{ $m($row['taxable_minor']) }}</td></tr>
        <tr><td>TVA {{ $row['category'] === 'S' ? rtrim(rtrim($row['rate'], '0'), '.').'%' : '' }}</td><td class="num">{{ $m($row['tax_minor']) }}</td></tr>
    @endforeach
    <tr><td>Total fără TVA</td><td class="num">{{ $m($invoice->net_total_minor) }}</td></tr>
    <tr><td>Total TVA</td><td class="num">{{ $m($invoice->vat_total_minor) }}</td></tr>
    @if ((int) $invoice->rounding_minor !== 0)<tr><td>Rotunjire</td><td class="num">{{ $m($invoice->rounding_minor) }}</td></tr>@endif
    <tr class="grand"><td>Total de plată</td><td class="num">{{ $m($invoice->gross_total_minor) }} {{ $invoice->currency }}</td></tr>
</table>

<div class="notes">
    @foreach ((array) $invoice->vat_breakdown as $row)
        @if (! empty($row['exemption_reason']))<p>{{ $row['exemption_reason'] }}</p>@endif
    @endforeach
    @if ($invoice->notes)<p>{!! nl2br(e($invoice->notes)) !!}</p>@endif
    @if ($invoice->relatedInvoice)<p>Document de referință: {{ $invoice->relatedInvoice->full_number }} din {{ $invoice->relatedInvoice->issue_date?->format('d.m.Y') }}.</p>@endif
    <p>Factura este valabilă fără semnătură și ștampilă (art. 319 alin. (29) din Codul fiscal).</p>
</div>
</body>
</html>
