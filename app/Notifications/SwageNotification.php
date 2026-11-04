<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Server;
use App\Models\ServerWebhook;
use App\Models\Site;
use App\Models\User;
use App\Notifications\Channels\WebhookChannel;
use App\Plugins\Attention;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Something worth telling people about. It shows under Notifications in the panel, reaches by email those who chose
 * to get its type by email, and posts to the webhooks of the server it is about.
 */
abstract class SwageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    abstract public function type(): NotificationType;

    /**
     * A short summary, used as the email's subject.
     */
    abstract public function title(): string;

    /**
     * What happened and what to do about it, in a sentence or two.
     */
    abstract public function message(): string;

    /**
     * The page in Swage to look at.
     */
    abstract public function url(): string;

    /**
     * Attention::HIGH, MEDIUM or LOW.
     */
    public function severity(): string
    {
        return Attention::HIGH;
    }

    /**
     * The server it is about, whose owner, Managers and webhooks receive it. Without one, it goes to administrators.
     */
    public function server(): ?Server
    {
        return null;
    }

    /**
     * The site it is about, whose Managers receive it too.
     */
    public function site(): ?Site
    {
        return null;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof ServerWebhook) {
            return [WebhookChannel::class];
        }

        return $notifiable instanceof User && $notifiable->wantsEmail($this->type()) ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting($this->title())
            ->line($this->message())
            ->action(__('Open in Swage'), $this->url());

        if ($server = $this->server()) {
            $mail->line(__('Server: :server (:ip)', ['server' => $server->name, 'ip' => $server->ip_address]));
        }

        return $this->severity() === Attention::HIGH ? $mail->error() : $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type()->value,
            'title' => $this->title(),
            'message' => $this->message(),
            'url' => $this->url(),
            'severity' => $this->severity(),
            'server' => $this->server()?->name,
        ];
    }
}
