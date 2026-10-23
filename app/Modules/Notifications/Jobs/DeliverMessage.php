<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Crm\Services\ConsentService;
use App\Modules\Notifications\Models\MessageLog;
use App\Modules\Notifications\Services\OutgoingMessage;
use App\Modules\Notifications\Services\ProviderFactory;
use App\Modules\Notifications\Providers\SandboxProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Livrarea efectivă a unui mesaj. Reîncercări cu backoff exponențial pentru erorile temporare.
 * Payload-ul (conținutul) este criptat în coadă.
 */
class DeliverMessage implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public int $logId, public string $address, public array $content) {}

    public function backoff(): array
    {
        return [60, 300, 900, 3600, 10800];
    }

    public function handle(ProviderFactory $providers, ConsentService $consents): void
    {
        $log = MessageLog::query()->find($this->logId);
        if (! $log || ! in_array($log->status, ['queued', 'sending'], true)) {
            return; // deja procesat — nu retrimitem
        }

        // verificare finală chiar înainte de trimitere (dezabonările din ultimul moment contează)
        if ($log->category === 'marketing' && (! $log->customer || ! $consents->canReceiveMarketing($log->customer, $log->channel))) {
            $log->forceFill(['status' => 'suppressed', 'last_error' => 'Consimțământ retras sau adresă exclusă înainte de trimitere.'])->save();

            return;
        }
        if ($consents->isSuppressed($log->channel, $this->address, 'service') && $log->category === 'service') {
            $log->forceFill(['status' => 'suppressed', 'last_error' => 'Adresă exclusă.'])->save();

            return;
        }

        $provider = $this->isSandboxed() ? new SandboxProvider : $providers->for($log->channel);

        $log->forceFill(['status' => 'sending', 'attempts' => $log->attempts + 1, 'provider' => $provider->name()])->save();

        $result = $provider->send(new OutgoingMessage(
            channel: $log->channel,
            to: $this->address,
            subject: $this->content['subject'] ?? null,
            text: (string) ($this->content['text'] ?? ''),
            html: $this->content['html'] ?? null,
            uuid: $log->uuid,
            category: $log->category,
            headers: (array) ($this->content['headers'] ?? []),
        ));

        if ($result->sandboxed) {
            $log->forceFill(['status' => 'sandboxed', 'provider' => 'sandbox', 'accepted_at' => now(), 'last_error' => null])->save();

            return;
        }

        if ($result->accepted) {
            $log->forceFill([
                'status' => 'accepted',
                'provider_message_id' => $result->messageId,
                'accepted_at' => now(),
                'cost_micro' => $result->costMicro,
                'segments' => $result->segments,
                'last_error' => null,
            ])->save();

            return;
        }

        $log->forceFill(['last_error' => $result->error])->save();
        if ($result->retryable && $this->attempts() < $this->tries) {
            $log->forceFill(['status' => 'queued', 'next_attempt_at' => now()->addSeconds($this->backoff()[min($this->attempts() - 1, 4)])])->save();
            $this->release($this->backoff()[min($this->attempts() - 1, 4)]);

            return;
        }

        $log->forceFill(['status' => 'failed', 'failed_at' => now()])->save();
    }

    public function failed(\Throwable $e): void
    {
        MessageLog::query()->whereKey($this->logId)->whereIn('status', ['queued', 'sending'])->update([
            'status' => 'failed',
            'failed_at' => now(),
            'last_error' => mb_substr($e->getMessage(), 0, 500),
        ]);
    }

    /** În afara producției, doar destinatarii de test primesc mesaje reale. */
    private function isSandboxed(): bool
    {
        if (! config('aw.messaging.force_sandbox')) {
            return false;
        }
        $tests = array_map('strtolower', array_merge(config('aw.messaging.test_recipients_email', []), config('aw.messaging.test_recipients_sms', [])));

        return ! in_array(strtolower($this->address), $tests, true);
    }
}
