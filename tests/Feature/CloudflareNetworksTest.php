<?php

use App\Enums\ResourceStatus;
use App\Models\Server;
use App\Models\Site;
use App\Provisioning\ServerTuner;
use App\Provisioning\Upgrades\CloudflareVisitorAddresses;
use App\Sites\CloudflareNetworks;
use App\Ssh\Script;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Run the daily refresh against stand-ins for curl, nginx and systemctl, returning its result and the config it left.
 *
 * @return array{0: ProcessResult, 1: string, 2: string}
 */
function runCloudflareRefresh(string $v4, string $v6, bool $nginxAccepts = true): array
{
    return runCloudflareRefreshFrom(CURRENT_CLOUDFLARE_CONFIG, $v4, $v6, $nginxAccepts);
}

const CURRENT_CLOUDFLARE_CONFIG = "set_real_ip_from 10.0.0.0/8;\nset_real_ip_from fd00::/8;\n";

/**
 * @return array{0: ProcessResult, 1: string, 2: string}
 */
function runCloudflareRefreshFrom(string $current, string $v4, string $v6, bool $nginxAccepts): array
{
    $directory = sys_get_temp_dir().'/swage-cloudflare-'.Str::random(8);
    File::ensureDirectoryExists("{$directory}/bin");

    // The partial has more heredocs after the refresh's, so the script ends at the first delimiter after it, not the last.
    $script = Str::before(Str::after(Script::render('partials.cloudflare-networks', ['server' => Server::factory()->make(['id' => 7])]), "<<'SWAGE_EOF'\n#!/usr/bin/env bash"), "\nSWAGE_EOF");
    File::put("{$directory}/refresh", '#!/usr/bin/env bash'.str_replace(CloudflareNetworks::CONFIG_PATH, "{$directory}/cloudflare.conf", $script));
    File::put("{$directory}/cloudflare.conf", $current);
    File::put("{$directory}/v4", $v4);
    File::put("{$directory}/v6", $v6);
    File::put("{$directory}/bin/curl", "#!/usr/bin/env bash\ncase \"\$*\" in *ips-v4*) cat {$directory}/v4 ;; *ips-v6*) cat {$directory}/v6 ;; esac\n");
    File::put("{$directory}/bin/nginx", $nginxAccepts ? "#!/usr/bin/env bash\nexit 0\n" : "#!/usr/bin/env bash\nexit 1\n");
    File::put("{$directory}/bin/systemctl", "#!/usr/bin/env bash\necho \"\$*\" >> {$directory}/systemctl.log\n");
    collect(['refresh', 'bin/curl', 'bin/nginx', 'bin/systemctl'])->each(fn (string $file) => chmod("{$directory}/{$file}", 0755));

    $result = Process::env(['PATH' => "{$directory}/bin:".getenv('PATH')])->run("{$directory}/refresh");
    $config = File::get("{$directory}/cloudflare.conf");
    $reloads = File::exists("{$directory}/systemctl.log") ? File::get("{$directory}/systemctl.log") : '';

    File::deleteDirectory($directory);

    return [$result, $config, $reloads];
}

test('sites include the server\'s Cloudflare ranges and take the visitor\'s address from them', function () {
    $config = Script::render('sites.nginx', ['site' => Site::factory()->create(), 'certificate' => null]);

    expect($config)->toContain('include '.CloudflareNetworks::CONFIG_PATH.';')
        ->toContain('real_ip_header CF-Connecting-IP;')
        ->not->toContain('set_real_ip_from');
});

test('app servers are provisioned with the ranges and a daily refresh', function () {
    $server = Server::factory()->create(['id' => 75]);
    $tuning = (new ServerTuner)->tune($server->specs(), $server->usesMysql(), (bool) $server->has_redis, $server->usesPostgres());

    expect(Script::render('provision.server', ['server' => $server, 'tuning' => $tuning]))
        ->toContain("set_real_ip_from 173.245.48.0/20;\n")
        ->toContain('cat > /usr/local/sbin/swage-cloudflare-ips')
        ->toContain('15 4 * * * root /usr/local/sbin/swage-cloudflare-ips 2>&1 | logger -t swage-cloudflare-ips');
});

test('database servers get no Cloudflare ranges', function () {
    $server = Server::factory()->database()->create();
    $tuning = (new ServerTuner)->tune($server->specs(), true, false, withPostgres: false, withPhp: false);

    expect(Script::render('provision.server', ['server' => $server, 'tuning' => $tuning]))->not->toContain('swage-cloudflare-ips');
});

test('the upgrade installs the refresh and rewrites the nginx config of installed sites', function () {
    $shell = fakeShell();
    $server = Server::factory()->create();
    $installed = Site::factory()->for($server)->create(['domain' => 'shop.example.com', 'status' => ResourceStatus::Installed]);
    Site::factory()->for($server)->create(['domain' => 'new.example.com', 'status' => ResourceStatus::Installing]);

    app(CloudflareVisitorAddresses::class)->apply($server);

    $script = $shell->lastScript();

    expect($script)
        ->toContain('/etc/cron.d/swage-cloudflare-ips')
        ->toContain("/etc/nginx/sites-available/{$installed->domain}")
        ->toContain('include '.CloudflareNetworks::CONFIG_PATH.';')
        ->not->toContain('/etc/nginx/sites-available/new.example.com')
        ->and(strpos($script, 'cat > '.CloudflareNetworks::CONFIG_PATH))->toBeLessThan(strpos($script, 'nginx -t'));
});

describe('the daily refresh', function () {
    beforeEach(function () {
        if (! Process::run('command -v bash')->successful()) {
            $this->markTestSkipped('bash is not available to run the refresh.');
        }
    });

    test('writes Cloudflare\'s current ranges and reloads nginx', function () {
        [$result, $config, $reloads] = runCloudflareRefresh("173.245.48.0/20\r\n103.21.244.0/22\n", "2400:cb00::/32\n");

        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and($config)->toBe("set_real_ip_from 173.245.48.0/20;\nset_real_ip_from 103.21.244.0/22;\nset_real_ip_from 2400:cb00::/32;\n")
            ->and($reloads)->toBe("reload nginx\n");
    });

    test('leaves nginx alone when the ranges have not changed', function () {
        [$result, $config, $reloads] = runCloudflareRefresh("10.0.0.0/8\n", "fd00::/8\n");

        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and($config)->toBe(CURRENT_CLOUDFLARE_CONFIG)
            ->and($reloads)->toBe('');
    });

    test('keeps the current ranges when Cloudflare sends something unexpected', function (string $v4, string $v6) {
        [$result, $config, $reloads] = runCloudflareRefresh($v4, $v6);

        expect($result->successful())->toBeFalse()
            ->and($config)->toBe(CURRENT_CLOUDFLARE_CONFIG)
            ->and($reloads)->toBe('');
    })->with([
        'no IPv4 ranges' => ['', "2400:cb00::/32\n"],
        'no IPv6 ranges' => ["173.245.48.0/20\n", ''],
        'an error page' => ["<html>Rate limited</html>\n", "2400:cb00::/32\n"],
        'an injected directive' => ["173.245.48.0/20; include /etc/passwd\n", "2400:cb00::/32\n"],
    ]);

    test('restores the previous ranges when nginx rejects the new ones', function () {
        [$result, $config, $reloads] = runCloudflareRefresh("173.245.48.0/20\n", "2400:cb00::/32\n", nginxAccepts: false);

        expect($result->successful())->toBeFalse()
            ->and($result->errorOutput())->toContain('previous ones were kept')
            ->and($config)->toBe(CURRENT_CLOUDFLARE_CONFIG)
            ->and($reloads)->toBe('');
    });
});
