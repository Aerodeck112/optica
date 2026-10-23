<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Crm\Services\ConsentService;
use App\Modules\Notifications\Models\MessageLog;

/**
 * Aplică rapoartele de livrare primite prin webhook. Bounce-urile definitive și reclamațiile adaugă adresa
 * pe lista de excludere; o stare „mai veche” nu suprascrie una finală.
 */
class DeliveryEventProcessor
{
    private const FINAL = ['delivered', 'bounced', 'complained', 'failed'];

    public function __construct(private readonly ConsentService $consents) {}

    public function apply(string $provider, array $event): void
    {
        $log = MessageLog::query()->where('provider', $provider)->where('provider_message_id', $event['message_id'])->first();

        $address = $event['address'] ?? null;
        $channel = $log?->channel ?? ($provider === 'twilio' ? 'sms' : 'email');

        switch ($event['status']) {
            case 'delivered':
                if ($log && ! in_array($log->status, ['bounced', 'complained'], true)) {
                    $log->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();
                }
                break;
            case 'bounced':
                $log?->forceFill(['status' => 'bounced', 'failed_at' => now(), 'last_error' => $event['reason'] ?? 'hard bounce'])->save();
                $this->consents->suppress($channel, $address, 'hard_bounce', 'all', $event['reason'] ?? null);
                break;
            case 'complained':
                $log?->forceFill(['status' => 'complained', 'last_error' => 'Marcat ca spam de destinatar'])->save();
                $this->consents->suppress($channel, $address, 'complaint', 'marketing');
                break;
            case 'unsubscribed':
                $this->consents->suppress($channel, $address, 'unsubscribed', 'marketing');
                break;
            case 'failed':
                if ($log && ! in_array($log->status, self::FINAL, true)) {
                    $log->forceFill(['status' => 'failed', 'failed_at' => now(), 'last_error' => $event['reason'] ?? 'eroare livrare'])->save();
                }
                break;
            case 'soft_bounced':
                $log?->forceFill(['last_error' => 'Respins temporar: '.($event['reason'] ?? '')])->save();
                break;
            case 'opened':
                if ($log && ! $log->opened_at) {
                    $log->forceFill(['opened_at' => now()])->save();
                }
                break;
            case 'clicked':
                if ($log && ! $log->clicked_at) {
                    $log->forceFill(['clicked_at' => now()])->save();
                }
                break;
        }
    }
}
