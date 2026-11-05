<?php

declare(strict_types=1);

namespace App\Kernel\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One reminder (overdue invoice, pending approval, …) delivered by email and/or the in-app bell. Carries labels,
 * amounts and a link only — never personal data beyond the document reference, never secrets.
 *
 * @see SRS G-05
 */
final class BusinessNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  list<string>  $channels  mail | database */
    public function __construct(
        public readonly string $event,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly array $channels,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->title)->line($this->body)->action('Open in Infernix One', $this->url);
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return ['event' => $this->event, 'title' => $this->title, 'body' => $this->body, 'url' => $this->url];
    }
}
