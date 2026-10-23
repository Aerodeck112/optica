<?php

namespace App\Modules\Notifications\Providers;

use App\Modules\Notifications\Contracts\MessageProvider;
use App\Modules\Notifications\Services\OutgoingMessage;
use App\Modules\Notifications\Services\ProviderResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Nu trimite nimic. Mesajul rămâne în jurnal cu starea „sandboxed” și apare în log-ul aplicației (fără conținut sensibil).
 */
class SandboxProvider implements MessageProvider
{
    public function name(): string
    {
        return 'sandbox';
    }

    public function send(OutgoingMessage $message): ProviderResult
    {
        Log::channel(config('logging.default'))->info('[sandbox] mesaj nelivrat', [
            'uuid' => $message->uuid,
            'channel' => $message->channel,
            'subject' => $message->subject,
            'length' => mb_strlen($message->text),
        ]);

        return ProviderResult::sandboxed();
    }

    public function supportsDeliveryReports(): bool
    {
        return false;
    }

    public function verifyWebhook(Request $request): bool
    {
        return false;
    }

    public function parseWebhook(Request $request): array
    {
        return [];
    }

    public function test(): array
    {
        return ['ok' => true, 'message' => 'Mod sandbox: mesajele nu sunt trimise.'];
    }
}
