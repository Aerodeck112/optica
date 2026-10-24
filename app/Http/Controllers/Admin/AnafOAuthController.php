<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Finance\Efactura\AnafClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Autorizarea OAuth2 ANAF (se deschide din panou, pe calculatorul cu certificatul digital al reprezentantului). */
class AnafOAuthController extends Controller
{
    public function authorize(Request $request, AnafClient $anaf)
    {
        abort_unless($request->user()?->can('efactura.manage'), 403);
        $state = Str::random(40);
        $request->session()->put('anaf_oauth_state', $state);

        return redirect()->away($anaf->authorizeUrl($state));
    }

    public function callback(Request $request, AnafClient $anaf)
    {
        abort_unless($request->user()?->can('efactura.manage'), 403);
        $expected = (string) $request->session()->pull('anaf_oauth_state');
        abort_unless($expected !== '' && hash_equals($expected, (string) $request->query('state')), 403, 'Stare OAuth invalidă.');
        $code = (string) $request->query('code');
        abort_if($code === '', 400, 'ANAF nu a trimis codul de autorizare.');

        $anaf->exchangeCode($code);
        $integration = $anaf->integration();
        $integration->forceFill(['status' => $integration->mode === 'live' ? 'configured' : 'sandbox_tested', 'last_tested_at' => now(), 'last_test_result' => 'Autorizare ANAF obținută.'])->save();
        app(AuditLogger::class)->log('efactura.authorized', $integration);

        return redirect(route('filament.admin.resources.integrations.edit', $integration))->with('status', 'Autorizarea ANAF a fost salvată.');
    }
}
