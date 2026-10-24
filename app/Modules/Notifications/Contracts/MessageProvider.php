<?php

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\Services\OutgoingMessage;
use App\Modules\Notifications\Services\ProviderResult;
use Illuminate\Http\Request;

/**
 * Adaptor pentru un furnizor de e-mail sau SMS. Aplicația nu depinde de un furnizor anume.
 */
interface MessageProvider
{
    public function name(): string;

    public function send(OutgoingMessage $message): ProviderResult;

    /** true dacă furnizorul trimite confirmări de livrare (webhook). */
    public function supportsDeliveryReports(): bool;

    public function verifyWebhook(Request $request): bool;

    /**
     * @return array<int, array{event_id:string, message_id:string, status:string, reason:?string, address:?string}>
     *                                                                                                               status: delivered | bounced | soft_bounced | complained | failed | unsubscribed | opened | clicked
     */
    public function parseWebhook(Request $request): array;

    /** Verificare de configurare (fără a trimite mesaje reale clienților). */
    public function test(): array;
}
