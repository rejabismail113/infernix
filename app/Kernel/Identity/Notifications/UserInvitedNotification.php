<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** @see SRS G-01, M12-01 */
final class UserInvitedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $acceptUrl, public readonly string $roleName) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You have been invited to Infernix One')
            ->line("You have been invited as {$this->roleName}. The invitation expires in 7 days.")
            ->action('Accept invitation', $this->acceptUrl);
    }
}
