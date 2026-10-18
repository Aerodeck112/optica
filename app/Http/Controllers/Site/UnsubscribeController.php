<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Support\Consent\ConsentRecorder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One-click withdrawal from marketing emails (MKT-02). The link is signed; showing the page does
 * not change anything, confirming does (mail scanners follow links).
 */
class UnsubscribeController extends Controller
{
    public function show(Request $request, string $uuid): Response
    {
        $message = Message::where('uuid', $uuid)->where('category', 'marketing')->firstOrFail();
        app()->setLocale($message->locale);

        return response()->view('site.unsubscribe', ['message' => $message, 'done' => false]);
    }

    public function store(Request $request, string $uuid, ConsentRecorder $consents): Response
    {
        $message = Message::with('patient')->where('uuid', $uuid)->where('category', 'marketing')->firstOrFail();
        app()->setLocale($message->locale);
        $consents->withdrawMarketing($message->patient_id, $message->patient?->phone, $message->channel === 'email' ? $message->recipient : $message->patient?->email,
            $message->channel, 'unsubscribe_link', $message->locale);

        return response()->view('site.unsubscribe', ['message' => $message, 'done' => true]);
    }
}
