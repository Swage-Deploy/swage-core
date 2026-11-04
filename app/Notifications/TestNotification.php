<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Server;
use App\Models\User;
use App\Plugins\Attention;

/**
 * Sent on request to check that notifications arrive: to a person in the panel and by email, or to a server's webhook.
 */
class TestNotification extends SwageNotification
{
    public function __construct(public ?Server $server = null) {}

    public function type(): NotificationType
    {
        return NotificationType::ServerAlert;
    }

    public function severity(): string
    {
        return Attention::LOW;
    }

    public function title(): string
    {
        return __('Test notification from Swage');
    }

    public function message(): string
    {
        return __('Notifications reach you here.');
    }

    public function url(): string
    {
        return $this->server ? route('servers.notifications', $this->server) : route('notifications.edit');
    }

    public function server(): ?Server
    {
        return $this->server;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? ['database', 'mail'] : parent::via($notifiable);
    }
}
