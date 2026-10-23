<?php

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\Services\OutgoingMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class RenderedMail extends Mailable
{
    public function __construct(private readonly OutgoingMessage $message, private readonly string $fromEmail, private readonly string $fromName) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: new Address($this->fromEmail, $this->fromName), subject: (string) $this->message->subject);
    }

    public function headers(): Headers
    {
        return new Headers(text: $this->message->headers + ['X-AW-Message' => $this->message->uuid]);
    }

    public function content(): Content
    {
        return new Content(htmlString: (string) $this->message->html, text: 'emails.plain', with: ['text' => $this->message->text]);
    }
}
