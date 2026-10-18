<?php

namespace App\Domain\Messaging\Channels;

use App\Models\ChannelAccount;
use App\Models\Message;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Email through the clinic's SMTP server. "Accepted" means the server took the message; SMTP gives
 * no delivery confirmation, so a message is never shown as delivered on this channel.
 */
class SmtpAdapter implements ChannelAdapter
{
    public function requiredCredentials(): array
    {
        return ['host', 'port', 'username', 'password'];
    }

    public function send(Message $message, ChannelAccount $account): SendResult
    {
        $transport = new EsmtpTransport(
            (string) $account->credential('host'),
            (int) $account->credential('port'),
            $account->credential('encryption') === 'ssl' ? true : null,
        );
        $transport->setUsername((string) $account->credential('username'));
        $transport->setPassword((string) $account->credential('password'));

        $email = (new Email)
            ->from(new Address((string) ($account->sender ?: $account->credential('username')), (string) ($account->credential('from_name') ?: 'Optica Optofarm')))
            ->to($message->recipient)
            ->subject((string) $message->subject)
            ->text($message->body)
            ->html('<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.5;color:#1d2b36">'.nl2br(e($message->body)).'</div>');
        $email->getHeaders()->addTextHeader('X-Optofarm-Message', $message->uuid);

        try {
            $sent = (new Mailer($transport))->send($email);
        } catch (TransportExceptionInterface $e) {
            // 5xx answers are final (unknown mailbox, rejected sender); anything else may pass later.
            throw (preg_match('/\b5\d\d\b/', $e->getMessage()) ? SendFailure::permanent($e->getMessage()) : SendFailure::transient($e->getMessage()));
        }

        return new SendResult('accepted', $email->getHeaders()->get('Message-ID')?->getBodyAsString() ?? 'smtp-'.$message->uuid);
    }
}
