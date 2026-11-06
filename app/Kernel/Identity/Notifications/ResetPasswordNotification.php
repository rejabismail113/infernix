<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** @see SRS G-01 */
final class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = $notifiable->getEmailForPasswordReset();
        $url = url(route('password.reset', ['token' => $this->token, 'email' => $email], false));

        return (new MailMessage)
            ->subject('Reset your Infernix Workspace password')
            ->line('You requested a password reset. The link expires in 60 minutes.')
            ->action('Reset password', $url)
            ->line('If you did not request this, no action is needed.');
    }
}
