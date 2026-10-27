<?php

use App\Models\Certificate;
use App\Models\Site;
use App\Ssh\Script;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function hostMigrations(): array
{
    return collect(File::files(resource_path('scripts/host/migrations')))
        ->map(fn (SplFileInfo $file) => $file->getFilename())
        ->sort()
        ->values()
        ->all();
}

test('host migrations are named so they apply in a fixed order', function () {
    expect(hostMigrations())->not->toBeEmpty();

    foreach (hostMigrations() as $name) {
        expect($name)->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.sh$/');
    }

    $prefixes = collect(hostMigrations())->map(fn (string $name) => substr($name, 0, 17));

    expect($prefixes->duplicates())->toBeEmpty();
});

test('ssh hardening only runs after the firewall allows ssh and refuses to run without a root key', function () {
    $names = hostMigrations();
    $firewall = collect($names)->search(fn ($name) => str_contains($name, 'firewall'));
    $ssh = collect($names)->search(fn ($name) => str_contains($name, 'ssh_hardening'));

    expect($firewall)->toBeLessThan($ssh)
        ->and(File::get(resource_path('scripts/host/migrations/'.$names[$ssh])))
        ->toContain('/root/.ssh/authorized_keys')
        ->toContain('PasswordAuthentication no');
});

test('the host runner only accepts full commit hashes on the tracked branch that do not go back in history', function () {
    $runner = File::get(resource_path('scripts/host/swage-host-migrate'));

    expect($runner)
        ->toContain('^[0-9a-f]{40}$')
        ->toContain('merge-base --is-ancestor "$COMMIT" FETCH_HEAD')
        ->toContain('merge-base --is-ancestor "$PREVIOUS" "$COMMIT"')
        ->toContain('StrictHostKeyChecking=yes');
});

test('host scripts are valid bash', function () {
    if (! Process::run('command -v bash')->successful()) {
        $this->markTestSkipped('bash is not available to check script syntax. Install it where PHP runs, such as with apk add bash in an Alpine container.');
    }

    $scripts = [
        resource_path('scripts/install-swage.sh'),
        resource_path('scripts/host/swage-host-migrate'),
        resource_path('scripts/host/migrate.sh'),
        resource_path('scripts/host/lib.sh'),
        ...collect(hostMigrations())->map(fn ($name) => resource_path("scripts/host/migrations/{$name}")),
    ];

    foreach ($scripts as $script) {
        $result = Process::run(['bash', '-n', $script]);

        expect($result->successful())->toBeTrue("{$script}: {$result->errorOutput()}");
    }
});

test('nginx configs stay compatible with the nginx 1.24 shipped by ubuntu 24.04', function () {
    $site = Site::factory()->create();
    $certificate = Certificate::factory()->for($site)->create();

    $managedSite = Script::render('sites.nginx', ['site' => $site, 'certificate' => $certificate]);
    $swageHost = File::get(resource_path('scripts/host/lib.sh'));

    foreach ([$managedSite, $swageHost] as $config) {
        expect($config)
            ->not->toMatch('/^\s*http2\s+on;/m')
            ->toContain('listen 443 ssl http2;');
    }
});

test('the app database user can connect over tcp when mysql skips name resolution', function () {
    $migrations = collect(hostMigrations())
        ->map(fn (string $name) => File::get(resource_path("scripts/host/migrations/{$name}")))
        ->implode("\n");

    expect($migrations)->toContain('skip-name-resolve')
        ->toContain("GRANT ALL PRIVILEGES ON swage.* TO 'swage'@'127.0.0.1'");
});

test('deployments get workers of their own, and only hosts that have them send deployments there', function () {
    $migration = file_get_contents(resource_path('scripts/host/migrations/2026_10_18_000015_deployment_workers.sh'));

    expect($migration)
        ->toContain('queue:work redis --queue=default')
        ->toContain('[program:swage-deployer]')
        ->toContain('queue:work redis --queue=deployments')
        ->toContain('as_app sh -c')
        ->and(file_get_contents(resource_path('scripts/install-swage.sh')))->toContain('set_env SWAGE_DEPLOYMENT_QUEUE deployments')
        ->and(config('swage.deployment_queue'))->toBe('default');
});

test('the installer refuses a cloudflare tunnel flag without a usable token', function (array $arguments, string $error) {
    if (! Process::run('command -v bash')->successful()) {
        $this->markTestSkipped('bash is not available to run the installer.');
    }

    $result = Process::run(['bash', resource_path('scripts/install-swage.sh'), ...$arguments]);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain($error);
})->with([
    'missing token' => [['--cloudflare-tunnel-token'], 'needs the tunnel\'s token'],
    'token with shell characters' => [['--cloudflare-tunnel-token=abc; rm -rf /'], 'does not look like a Cloudflare Tunnel token'],
    'unknown option' => [['--tunnel'], 'Unknown option: --tunnel'],
]);

test('a cloudflare tunnel install keeps its token root only and skips lets encrypt', function () {
    $installer = File::get(resource_path('scripts/install-swage.sh'));
    $ssl = File::get(resource_path('scripts/host/migrations/2026_10_14_000012_ssl.sh'));
    $tunnel = File::get(resource_path('scripts/host/migrations/2026_10_20_000016_cloudflare_tunnel.sh'));

    expect($installer)
        ->toContain('CLOUDFLARE_TUNNEL=$CLOUDFLARE_TUNNEL')
        ->toContain('(umask 077 && echo "TUNNEL_TOKEN=$CLOUDFLARE_TUNNEL_TOKEN" > /etc/swage/cloudflared.env)')
        ->toContain("if [ \"\$CLOUDFLARE_TUNNEL\" = true ]; then\n    set_env SWAGE_HEALTH_CHECK_URL \"http://127.0.0.1/up\"")
        ->and($ssl)->toContain('if [ "${CLOUDFLARE_TUNNEL:-false}" != true ]; then')
        ->and($tunnel)
        ->toContain('EnvironmentFile=/etc/swage/cloudflared.env')
        ->toContain('ExecStart=/usr/bin/cloudflared --no-autoupdate tunnel run')
        ->toContain("ufw delete allow 'Nginx Full'")
        ->not->toContain('--token');
});

test('behind a cloudflare tunnel nginx only listens for cloudflared and tells php requests are secure', function () {
    $lib = File::get(resource_path('scripts/host/lib.sh'));

    expect($lib)
        ->toContain('listen 127.0.0.1:80;')
        ->toContain('real_ip_header CF-Connecting-IP;')
        ->toContain("tunnel_https='fastcgi_param HTTPS on;'");
});

test('the installer keeps a root-only log of its output, which holds the administrator password', function () {
    $installer = File::get(resource_path('scripts/install-swage.sh'));

    expect($installer)
        ->toContain('LOG=/root/swage-install.log')
        ->toContain('(umask 077 && touch "$LOG")')
        ->toContain('exec > >(tee -a "$LOG") 2>&1');

    expect(strpos($installer, 'exec > >(tee -a "$LOG") 2>&1'))->toBeLessThan(strpos($installer, 'db:seed'));
});

test('tunnel installs restart nginx so it can move from every address to the loopback ones', function () {
    $lib = File::get(resource_path('scripts/host/lib.sh'));
    $repair = File::get(resource_path('scripts/host/migrations/2026_10_20_000017_restart_tunnel_nginx.sh'));

    expect($lib)->toContain("if [ \"\${CLOUDFLARE_TUNNEL:-false}\" = true ]; then\n        systemctl restart nginx\n    else\n        systemctl reload nginx")
        ->and($repair)->toContain('if [ "${CLOUDFLARE_TUNNEL:-false}" != true ]; then')
        ->toContain("ss -Hltn 'sport = :80'")
        ->toContain('systemctl restart nginx');
});
