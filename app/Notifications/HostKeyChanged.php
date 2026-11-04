<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Server;

class HostKeyChanged extends SwageNotification
{
    public function __construct(public Server $server) {}

    public function type(): NotificationType
    {
        return NotificationType::HostKeyChanged;
    }

    public function title(): string
    {
        return __('The SSH host key of :server changed', ['server' => $this->server->name]);
    }

    public function message(): string
    {
        return __('Swage stopped connecting to :ip, because its SSH host key no longer matches. If you rebuilt the server, remove it from Swage and connect it again. Otherwise someone may be intercepting the connection.', ['ip' => $this->server->ip_address]);
    }

    public function url(): string
    {
        return route('servers.show', $this->server);
    }

    public function server(): Server
    {
        return $this->server;
    }
}
