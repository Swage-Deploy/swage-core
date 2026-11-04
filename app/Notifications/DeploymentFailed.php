<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;

class DeploymentFailed extends SwageNotification
{
    public function __construct(public Deployment $deployment) {}

    public function type(): NotificationType
    {
        return NotificationType::DeploymentFailed;
    }

    public function title(): string
    {
        return __('Deploying :site failed', ['site' => $this->deployment->site->domain]);
    }

    public function message(): string
    {
        $commit = $this->deployment->shortHash();

        return $commit
            ? __('The deployment of :commit to :site failed, so the site keeps running its last release. Its output shows where it stopped.', ['commit' => $commit, 'site' => $this->deployment->site->domain])
            : __('The deployment of :site failed, so the site keeps running its last release. Its output shows where it stopped.', ['site' => $this->deployment->site->domain]);
    }

    public function url(): string
    {
        return route('servers.sites.show', [$this->deployment->site->server_id, $this->deployment->site_id]);
    }

    public function server(): Server
    {
        return $this->deployment->site->server;
    }

    public function site(): Site
    {
        return $this->deployment->site;
    }
}
