<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ $table['title'] }}</title>
    <style>
        @page { margin: 16mm 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; color: #1d2b36; }
        h1 { font-size: 13pt; color: #00466f; margin: 0 0 4px; }
        .muted { color: #5b6b78; font-size: 7.5pt; margin: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #d5dde3; padding: 3px 5px; text-align: left; vertical-align: top; }
        th { background: #f1f5f8; }
        td.n { text-align: right; }
    </style>
</head>
<body>
    <h1>Optica Optofarm – {{ $table['title'] }}</h1>
    @foreach ($filters as $line)<p class="muted">{{ $line }}</p>@endforeach
    <p class="muted">{{ $generated }}</p>
    <table>
        <thead><tr>@foreach ($table['headers'] as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($table['rows'] as $row)
            <tr>@foreach ($row as $v)<td @class(['n' => is_int($v) || is_float($v)])>{{ $v ?? '—' }}</td>@endforeach</tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>
