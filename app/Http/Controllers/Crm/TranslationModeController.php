<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Middleware\TranslationMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Switches translation mode on or off for the signed-in user's session (TRD-01). */
class TranslationModeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('translations.edit'), 403);
        $on = ! $request->session()->get(TranslationMode::SESSION_KEY, false);
        $request->session()->put(TranslationMode::SESSION_KEY, $on);
        $back = (string) $request->input('return_to', '/');

        // Only paths on this site; never an external address.
        return redirect()->to(str_starts_with($back, '/') && ! str_starts_with($back, '//') ? $back : '/');
    }
}
