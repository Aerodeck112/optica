<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Changes only the signed-in employee's language (LNG-02) and returns to the same page and filters.
 */
class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(config('optica.locales'))],
            'return_to' => ['nullable', 'string', 'max:2000'],
        ]);

        $request->user()->forceFill(['locale' => $data['locale']])->save();

        // Only same-site relative paths, to avoid open redirects.
        $target = $data['return_to'] ?? '';
        if (! str_starts_with($target, '/') || str_starts_with($target, '//')) {
            $target = route('crm.dashboard');
        }

        return redirect($target);
    }
}
