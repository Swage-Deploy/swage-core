<?php

namespace App\Provisioning\Upgrades;

use App\Actions\Servers\RunServerTask;
use App\Enums\ResourceStatus;
use App\Models\Server;
use App\Ssh\Script;

/**
 * Installs the ranges Cloudflare's proxy connects from, with a daily job that refreshes them, and rewrites every site's
 * nginx config so requests through the proxy are logged and passed to PHP with the visitor's address.
 */
final class CloudflareVisitorAddresses implements ServerUpgrade
{
    /**
     * Replaces 2026_10_23_cloudflare_visitor_addresses, which listed the ranges in each site's config and never refreshed
     * them, so servers that ran it get the refresh too.
     */
    public const string ID = '2026_10_23_cloudflare_networks';

    public function __construct(protected RunServerTask $runTask) {}

    public function title(): string
    {
        return __('See visitors\' addresses behind Cloudflare');
    }

    public function description(): string
    {
        return __('Sites proxied by Cloudflare see the visitor\'s address instead of Cloudflare\'s, in nginx logs, rate limiting and the app, and the server refreshes Cloudflare\'s address ranges every day. Sites that are not behind Cloudflare are unaffected. nginx reloads once.');
    }

    public function apply(Server $server): void
    {
        $sites = $server->sites()->where('status', ResourceStatus::Installed)->with('certificate')->get();

        $this->runTask->run($server, 'Showing visitors\' addresses behind Cloudflare', Script::render('upgrades.cloudflare-visitor-addresses', [
            'server' => $server,
            'sites' => $sites,
        ]), timeout: 300);
    }
}
