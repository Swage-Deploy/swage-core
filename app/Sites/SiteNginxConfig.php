<?php

namespace App\Sites;

use App\Enums\ConfigSyntax;
use App\Models\Certificate;
use App\Models\Site;
use App\Provisioning\ConfigMerger;
use App\Ssh\Script;

/**
 * The nginx config Swage writes for a site, with the site's custom nginx taking precedence.
 */
final class SiteNginxConfig
{
    public function __construct(
        private readonly Site $site,
        private readonly ?Certificate $certificate,
    ) {}

    /**
     * Build the config for the site's current certificate.
     */
    public static function for(Site $site): self
    {
        return new self($site, $site->certificate?->isActive() ? $site->certificate : null);
    }

    /**
     * Where the site's custom nginx is written. It is included inside the site's main server block.
     */
    public static function overridePath(Site $site): string
    {
        return "/etc/nginx/swage/sites/{$site->domain}.conf";
    }

    /**
     * Swage's own config for the site, before the custom nginx is applied.
     */
    public function base(): string
    {
        return rtrim(Script::render('sites.nginx', ['site' => $this->site, 'certificate' => $this->certificate]));
    }

    /**
     * The files to write for the site, keyed by path.
     *
     * @return array<string, string>
     */
    public function files(?string $override): array
    {
        $override = filled($override) ? rtrim(str_replace("\r\n", "\n", $override)) : null;

        return [
            "/etc/nginx/sites-available/{$this->site->domain}" => ConfigMerger::withoutOverridden($this->base(), $override, ConfigSyntax::Nginx, 'server')."\n",
            self::overridePath($this->site) => $override === null ? '' : $override."\n",
        ];
    }
}
