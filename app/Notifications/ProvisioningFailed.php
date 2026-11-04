<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Server;

class ProvisioningFailed extends SwageNotification
{
    public function __construct(public Server $server) {}

    public function type(): NotificationType
    {
        return NotificationType::ProvisioningFailed;
    }

    public function title(): string
    {
        return __('Provisioning :server failed', ['server' => $this->server->name]);
    }

    public function message(): string
    {
        return __('Provisioning stopped at ":step". Fix the cause, then run the new command on the server\'s page.', ['step' => $this->server->provisioning_step]);
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
