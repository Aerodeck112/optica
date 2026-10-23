<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class FiscalAgentController extends Controller
{
    public function commands(): JsonResponse
    {
        return response()->json(['error' => 'not_configured'], 404);
    }

    public function result(): JsonResponse
    {
        return response()->json(['error' => 'not_configured'], 404);
    }

    public function heartbeat(): JsonResponse
    {
        return response()->json(['error' => 'not_configured'], 404);
    }
}
