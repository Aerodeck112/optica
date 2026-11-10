<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\DisplayApiController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Display\DisplayController;
use App\Modules\Display\Models\DisplayScreen;
use Illuminate\Http\Request;

/** Previzualizarea unui ecran din CRM, cu sesiunea utilizatorului (nu creează și nu folosește credențiala dispozitivului). */
class DisplayPreviewController extends Controller
{
    public function show(DisplayScreen $screen)
    {
        $this->authorizeView();

        return response()->view('display.player', ['config' => [
            'mode' => 'preview',
            'slug' => $screen->slug,
            'type' => $screen->type,
            'version' => DisplayController::PLAYER_VERSION,
            'stateUrl' => route('admin.display.preview.state', $screen),
            'heartbeatUrl' => null,
            'activateUrl' => null,
            'swUrl' => null,
        ]])->withHeaders(DisplayController::privateHeaders());
    }

    public function state(Request $request, DisplayScreen $screen)
    {
        $this->authorizeView();

        return app(DisplayApiController::class)->respond($request, $screen, false);
    }

    private function authorizeView(): void
    {
        abort_unless(auth()->user()?->can('display.manage') || auth()->user()?->can('display.control'), 403);
    }
}
