<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Server;
use App\Plugins\Attention;

class RebootRequired extends SwageNotification
{
    public function __construct(public Server $server) {}

    public function type(): NotificationType
    {
        return NotificationType::RebootRequired;
    }

    public function severity(): string
    {
        return Attention::MEDIUM;
    }

    public function title(): string
    {
        return __(':server needs a reboot', ['server' => $this->server->name]);
    }

    public function message(): string
    {
        $packages = $this->server->reboot_required_packages ?? [];

        return $packages
            ? __('Updates to :packages take effect once it reboots. Reboot it from its page when a minute or two of downtime suits you.', ['packages' => implode(', ', $packages)])
            : __('An update takes effect once it reboots. Reboot it from its page when a minute or two of downtime suits you.');
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
