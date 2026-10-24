<?php

namespace App\Modules\Notifications\Providers;

use App\Modules\Notifications\Contracts\MessageProvider;
use App\Modules\Notifications\Mail\RenderedMail;
use App\Modules\Notifications\Services\OutgoingMessage;
use App\Modules\Notifications\Services\ProviderResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * SMTP generic (ex: serverul de e-mail din cPanel). Confirmă doar acceptarea de către server, nu și livrarea.
 */
class SmtpEmailProvider implements MessageProvider
{
    public function __construct(private readonly array $credentials, private readonly array $options) {}

    public function name(): string
    {
        return 'smtp';
    }

    public function send(OutgoingMessage $message): ProviderResult
    {
        try {
            $mailer = Mail::build([
                'transport' => 'smtp',
                'host' => $this->credentials['host'] ?? null,
                'port' => (int) ($this->credentials['port'] ?? 587),
                'username' => $this->credentials['username'] ?? null,
                'password' => $this->credentials['password'] ?? null,
                'scheme' => ($this->credentials['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
                'timeout' => 20,
            ]);
            $sent = $mailer->to($message->to)->send(new RenderedMail($message, $this->options['from_email'] ?? config('mail.from.address'), $this->options['from_name'] ?? config('mail.from.name')));

            return ProviderResult::accepted($sent?->getMessageId());
        } catch (TransportExceptionInterface $e) {
            return ProviderResult::failed(mb_substr($e->getMessage(), 0, 300), true);
        } catch (\Throwable $e) {
            return ProviderResult::failed(mb_substr($e->getMessage(), 0, 300), false);
        }
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
        try {
            $transport = Mail::build([
                'transport' => 'smtp',
                'host' => $this->credentials['host'] ?? null,
                'port' => (int) ($this->credentials['port'] ?? 587),
                'username' => $this->credentials['username'] ?? null,
                'password' => $this->credentials['password'] ?? null,
                'scheme' => ($this->credentials['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
                'timeout' => 10,
            ])->getSymfonyTransport();
            if (method_exists($transport, 'start')) {
                $transport->start();
            }

            return ['ok' => true, 'message' => 'Conexiunea SMTP a reușit.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Conexiunea SMTP a eșuat: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }
}
