<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Models\AiConversation;
use App\Modules\Ai\Services\AssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint-urile asistentului virtual. Conversația este legată de sesiunea browserului (nu poate fi preluată
 * de altcineva doar cu ID-ul); limitele de rată sunt aplicate pe rută (throttle:ai-chat).
 */
class ChatController extends Controller
{
    public function __construct(private readonly AssistantService $assistant) {}

    public function message(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => ['nullable', 'uuid'],
            'message' => ['required', 'string', 'max:800'],
        ]);
        $conversation = $this->assistant->conversation($data['conversation_id'] ?? null, $request->session()->getId(), (string) $request->ip(), app()->getLocale());
        $result = $this->assistant->reply($conversation, $data['message']);

        return response()->json($result, $result['status'] === 'unavailable' ? 503 : 200);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => ['required', 'uuid'],
            'summary_id' => ['required', 'string', 'size:16', 'alpha_num'],
        ]);
        $conversation = AiConversation::query()
            ->where('public_id', $data['conversation_id'])
            ->where('session_hash', hash('sha256', $request->session()->getId()))
            ->firstOrFail();

        return response()->json($this->assistant->confirm($conversation, $data['summary_id'], (string) $request->ip()));
    }
}
