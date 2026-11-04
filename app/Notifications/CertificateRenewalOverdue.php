<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Certificate;
use App\Models\Server;
use App\Models\Site;
use App\Plugins\Attention;

class CertificateRenewalOverdue extends SwageNotification
{
    public function __construct(public Certificate $certificate) {}

    public function type(): NotificationType
    {
        return NotificationType::CertificateRenewalOverdue;
    }

    public function severity(): string
    {
        return Attention::MEDIUM;
    }

    public function title(): string
    {
        return __('The certificate for :site has not renewed', ['site' => $this->certificate->site->domain]);
    }

    public function message(): string
    {
        return __('It expires :date, and certbot should have renewed it by now. Check that the domains still point at the server, then obtain the certificate again.', [
            'date' => $this->certificate->expires_at?->toFormattedDateString(),
        ]);
    }

    public function url(): string
    {
        return route('servers.sites.ssl', [$this->certificate->site->server_id, $this->certificate->site_id]);
    }

    public function server(): Server
    {
        return $this->certificate->site->server;
    }

    public function site(): Site
    {
        return $this->certificate->site;
    }
}
