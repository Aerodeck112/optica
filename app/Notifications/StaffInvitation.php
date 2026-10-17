<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation to set the first password, in the employee's own language (LNG-01).
 */
class StaffInvitation extends Notification
{
    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->preferredLocale();
        $url = route('crm.password.reset', ['token' => $this->token, 'email' => $notifiable->email, 'invite' => 1]);

        return (new MailMessage)
            ->subject(__('users.invitation.subject', [], $locale))
            ->greeting(__('users.invitation.greeting', ['name' => $notifiable->name], $locale))
            ->line(__('users.invitation.intro', [], $locale))
            ->action(__('users.invitation.action', [], $locale), $url)
            ->line(__('users.invitation.expiry', [], $locale))
            ->salutation(__('users.invitation.salutation', [], $locale));
    }
}
