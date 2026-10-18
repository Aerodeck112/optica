@props(['locale', 'logo' => null, 'tz', 'location' => null, 'title' => ''])
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 22mm 18mm 20mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #1d2b36; line-height: 1.45; }
        header { border-bottom: 2px solid #00466f; padding-bottom: 8px; margin-bottom: 16px; }
        header table { width: 100%; }
        header img { height: 48px; }
        .brand { font-size: 14pt; font-weight: bold; color: #00466f; }
        .muted { color: #5b6b78; font-size: 8.5pt; }
        h1 { font-size: 14pt; margin: 0 0 4px; color: #00466f; }
        h2 { font-size: 11pt; margin: 16px 0 6px; color: #00466f; border-bottom: 1px solid #d5dde3; padding-bottom: 2px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th, table.data td { border: 1px solid #d5dde3; padding: 4px 6px; text-align: left; vertical-align: top; }
        table.data th { background: #f1f5f8; font-weight: bold; }
        .meta td { padding: 1px 12px 1px 0; }
        .pre { white-space: pre-line; }
        footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7.5pt; color: #5b6b78; text-align: center; }
        .notice { margin-top: 18px; font-size: 8pt; color: #5b6b78; }
    </style>
</head>
<body>
<header>
    <table><tr>
        <td style="width: 60px">@if ($logo)<img src="{{ $logo }}" alt="">@endif</td>
        <td>
            <div class="brand">Optica Optofarm</div>
            <div class="muted">{{ $location?->name($locale) }}@if ($location?->address) · {{ $location->address }}@endif @if ($location?->phone) · {{ $location->phone }}@endif</div>
        </td>
    </tr></table>
</header>
<footer>{{ __('patients.pdf.footer', ['date' => now($tz)->format('d.m.Y H:i')], $locale) }}</footer>
{{ $slot }}
</body>
</html>
