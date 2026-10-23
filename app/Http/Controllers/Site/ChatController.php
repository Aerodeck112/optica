<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ChatController extends Controller
{
    public function message(): JsonResponse
    {
        return response()->json(['status' => 'unavailable', 'message' => __('site.chat.unavailable')], 503);
    }

    public function confirm(): JsonResponse
    {
        return response()->json(['status' => 'unavailable', 'message' => __('site.chat.unavailable')], 503);
    }
}
