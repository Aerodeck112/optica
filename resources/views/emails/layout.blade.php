<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#0a1033;font-family:Arial,Helvetica,sans-serif;color:#eef0ff;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0a1033;">
<tr><td align="center" style="padding:28px 12px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#05040f;border:1px solid #1c2668;border-radius:16px;">
        <tr><td style="padding:28px 32px 8px;">
            <img src="{{ url('/media/brand/og-default.jpg') }}" alt="Another World Cluj" width="536" style="display:block;width:100%;max-width:536px;height:auto;border-radius:10px;">
        </td></tr>
        <tr><td style="padding:16px 32px 8px;font-size:16px;line-height:1.6;color:#eef0ff;">
            <div class="aw-body" style="color:#eef0ff;">{!! str_replace('<a ', '<a style="color:#3ee6ff;" ', $body) !!}</div>
        </td></tr>
        <tr><td style="padding:16px 32px 28px;font-size:13px;line-height:1.5;color:#a9b0d6;border-top:1px solid #1c2668;">
            <p style="margin:12px 0 0;">Another World Cluj — arenă de realitate virtuală, Cluj-Napoca.</p>
            @if (! empty($contact['address']))<p style="margin:4px 0 0;">{{ $contact['address'] }}</p>@endif
            @if (! empty($contact['phone']) || ! empty($contact['email']))<p style="margin:4px 0 0;">{{ implode(', ', array_filter([$contact['phone'] ?? null, $contact['email'] ?? null])) }}</p>@endif
            @if ($unsubscribeUrl)<p style="margin:12px 0 0;">Primești acest mesaj pentru că te-ai abonat la noutăți. <a href="{{ $unsubscribeUrl }}" style="color:#3ee6ff;">Dezabonează-te</a></p>@endif
        </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
