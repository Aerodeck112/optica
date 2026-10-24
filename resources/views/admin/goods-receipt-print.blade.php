<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<title>NIR {{ $receipt->number }}</title>
<style>
  @page { size: A4 landscape; margin: 12mm; }
  body { font-family: "DejaVu Sans", system-ui, sans-serif; font-size: 10pt; color: #111; margin: 0; }
  .wrap { max-width: 270mm; margin: 8mm auto; }
  h1 { font-size: 14pt; text-align: center; margin: 4mm 0 1mm; }
  .sub { text-align: center; margin-bottom: 6mm; }
  .meta { display: flex; justify-content: space-between; gap: 10mm; margin-bottom: 5mm; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #555; padding: 1.5mm 2mm; vertical-align: top; }
  th { background: #eee; font-weight: 600; }
  td.num { text-align: right; white-space: nowrap; }
  .sign { display: flex; justify-content: space-between; margin-top: 14mm; }
  .sign div { width: 30%; border-top: 1px solid #555; padding-top: 2mm; text-align: center; }
  .print-bar { text-align: center; margin: 6mm; font: 14px system-ui; }
  @media print { .print-bar { display: none; } .wrap { margin: 0; } }
</style>
</head>
<body>
<div class="print-bar"><button onclick="window.print()">Tipărește</button></div>
<div class="wrap">
    <div class="meta">
        <div><strong>{{ $company['name'] ?? 'Unitatea' }}</strong><br>CUI {{ $company['tax_id'] ?? '—' }}<br>Gestiunea: {{ $receipt->warehouse?->name }}</div>
        <div style="text-align:right">Nr. {{ $receipt->number }}<br>Data: {{ $receipt->received_on?->format('d.m.Y') }}</div>
    </div>
    <h1>NOTĂ DE RECEPȚIE ȘI CONSTATARE DE DIFERENȚE</h1>
    <p class="sub">
        Furnizor: {{ $receipt->supplier?->name ?? '—' }}@if ($receipt->supplier?->tax_id) (CUI {{ $receipt->supplier->tax_id }})@endif
        @if ($receipt->purchaseInvoice) · Factura nr. {{ $receipt->purchaseInvoice->number }} din {{ $receipt->purchaseInvoice->issue_date?->format('d.m.Y') }}@endif
    </p>
    <table>
        <thead>
            <tr><th>Nr.</th><th>Denumire</th><th>U.M.</th><th>Cant. document</th><th>Cant. recepționată</th><th>Preț unitar (fără TVA)</th><th>Valoare</th></tr>
        </thead>
        <tbody>
        @php $total = 0; @endphp
        @foreach ($receipt->lines as $i => $line)
            @php
                $value = (float) $line->quantity * (float) $line->unit_cost;
                $total += $value;
                $documentQty = $line->purchaseInvoiceLine?->quantity;
            @endphp
            <tr>
                <td class="num">{{ $i + 1 }}</td>
                <td>{{ $line->item?->name }}</td>
                <td>{{ $line->item?->unit?->symbol }}</td>
                <td class="num">{{ $documentQty !== null ? rtrim(rtrim(number_format((float) $documentQty, 3, ',', '.'), '0'), ',') : '—' }}</td>
                <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 3, ',', '.'), '0'), ',') }}</td>
                <td class="num">{{ number_format((float) $line->unit_cost, 2, ',', '.') }}</td>
                <td class="num">{{ number_format($value, 2, ',', '.') }}</td>
            </tr>
        @endforeach
        <tr><td colspan="6" style="text-align:right"><strong>Total</strong></td><td class="num"><strong>{{ number_format($total, 2, ',', '.') }}</strong></td></tr>
        </tbody>
    </table>
    @if ($receipt->notes)<p><strong>Observații / diferențe constatate:</strong> {{ $receipt->notes }}</p>@endif
    @if ($receipt->status === 'cancelled')<p><strong>DOCUMENT ANULAT</strong></p>@endif
    <div class="sign">
        <div>Comisia de recepție<br>{{ $receipt->receiver?->name }}</div>
        <div>Gestionar</div>
        <div>Delegat furnizor</div>
    </div>
</div>
</body>
</html>
