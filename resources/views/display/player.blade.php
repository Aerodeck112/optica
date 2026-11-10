<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>Another World Cluj — {{ $config['type'] === 'live_arena' ? 'Live Arena' : 'Hall of Legends' }}</title>
    <link rel="icon" href="/favicon.ico">
    @vite(['resources/css/display.css', 'resources/js/display.js'])
</head>
<body>
    <div class="viewport">
        <div class="stage" id="stage">
            <div class="bg" aria-hidden="true">
                <div class="bg__nebula"></div>
                <div class="bg__image" data-bg-image></div>
                <div class="bg__portal"></div>
                <div class="bg__grid"></div>
                <canvas class="bg__canvas" width="1920" height="1080" data-particles></canvas>
            </div>
            <div class="hud" data-hud>
                <img class="hud__logo" src="/media/brand/aw-logo-horizontal.svg" alt="Another World Cluj">
                <div class="hud__right">
                    <div class="hud__date" data-hud-date></div>
                    <div class="hud__clock" data-hud-clock></div>
                </div>
            </div>
            <div data-scenes></div>
            <div class="conn" data-conn role="status"></div>
        </div>
    </div>
    <template id="logo-stacked">{!! file_get_contents(public_path('media/brand/aw-logo-stacked-cluj.svg')) !!}</template>
    <script type="application/json" id="display-config" nonce="{{ csp_nonce() }}">{!! json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
</body>
</html>
