<?php

use App\Enums\DeploymentStatus;
use App\Enums\ResourceStatus;
use App\Enums\TaskStatus;
use App\Exceptions\HostKeyMismatchException;
use App\Jobs\DeploySite;
use App\Jobs\InstallSite;
use App\Jobs\ObtainCertificate;
use App\Jobs\RemoveSite;
use App\Models\Certificate;
use App\Models\Server;
use App\Models\Site;
use App\Models\Task;
use App\Models\User;
use App\Sites\SiteEnvironment;
use App\Ssh\PhpseclibRemoteShell;
use App\Ssh\ScriptResult;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use phpseclib4\Net\SSH2;

/**
 * Check a host key the way the SSH shell does, against an SSH connection that presents the given key.
 */
function verifyHostKey(Server $server, string $presentedKey): void
{
    $ssh = Mockery::mock(SSH2::class);
    $ssh->shouldReceive('getServerPublicHostKey')->andReturn($presentedKey);

    (new class extends PhpseclibRemoteShell
    {
        public function check(Server $server, SSH2 $ssh): void
        {
            $this->verifyHostKey($server, $ssh);
        }
    })->check($server, $ssh);
}

test('a server without a pinned host key is never connected to', function () {
    $server = Server::factory()->create(['host_key' => null]);

    expect(fn () => verifyHostKey($server, 'ssh-ed25519 AAAAfirst'))->toThrow(RuntimeException::class);
    expect($server->fresh()->host_key)->toBeNull();
});

test('a server refuses a host key that differs from the pinned one', function () {
    $server = Server::factory()->create(['host_key' => 'ssh-ed25519 AAAApinned']);

    verifyHostKey($server, 'ssh-ed25519 AAAApinned');

    expect(fn () => verifyHostKey($server, 'ssh-ed25519 AAAAattacker'))->toThrow(HostKeyMismatchException::class);
});

test('a pinned host key cannot be replaced from the dashboard', function () {
    $server = Server::factory()->create(['host_key' => 'ssh-ed25519 AAAApinned']);

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->assertSee('SSH host key')
        ->assertDontSee('Trust a new key');

    expect($server->fresh()->host_key)->toBe('ssh-ed25519 AAAApinned');
});

test('provisioning pins the host key', function () {
    $server = Server::factory()->pending()->create(['redis_password' => null]);

    $this->get(route('provision.script', $server->provision_token))->assertSee('--data-urlencode "host_key=${HOST_KEY}"', escape: false);

    $script = $this->post(route('provision.specs', $server->provision_token), [
        'cpu_cores' => 2,
        'memory_mb' => 4096,
        'disk_gb' => 80,
        'os_version' => '24.04',
        'host_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample+key/0=',
    ])->assertOk()->getContent();

    $server->refresh();

    expect($server->host_key)->toBe('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample+key/0=')
        ->and($server->redis_password)->toHaveLength(40)
        ->and($script)
        ->toContain('usermod -aG "$SWAGE_USER" www-data')
        ->toContain("user www-data;\n")
        ->toContain('swage ALL=(root) NOPASSWD: /usr/local/sbin/swage-reload-php, /usr/local/sbin/swage-restart-daemon')
        ->toContain('user default off')
        ->not->toContain('php*-fpm');
});

test('reported host keys must be ed25519 keys', function () {
    $server = Server::factory()->pending()->create();

    $this->postJson(route('provision.specs', $server->provision_token), [
        'cpu_cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 80, 'os_version' => '24.04',
        'host_key' => "ssh-rsa AAAA'; rm -rf /",
    ])->assertUnprocessable()->assertJsonValidationErrors('host_key');
});

test('the provision token stops working once provisioning finishes and changes when it fails', function () {
    $finished = Server::factory()->provisioning()->create();
    $token = $finished->provision_token;

    $this->post(route('provision.finished', $token))->assertNoContent();

    expect($finished->fresh()->provision_token)->toBeNull();

    $failed = Server::factory()->provisioning()->create();
    $token = $failed->provision_token;

    $this->post(route('provision.failed', $token), ['line' => 12])->assertNoContent();

    expect($failed->fresh()->provision_token)->not->toBe($token)->toHaveLength(64);
    $this->get(route('provision.script', $token))->assertNotFound();
    $this->get(route('provision.script', $failed->fresh()->provision_token))->assertOk();
});

test('sites without a database user get blank mysql credentials instead of swage\'s account', function () {
    $site = Site::factory()->create();

    $environment = SiteEnvironment::defaults($site);

    expect($environment)
        ->toContain("DB_USERNAME=\nDB_PASSWORD=\n")
        ->not->toContain($site->server->database_password)
        ->not->toContain('DB_USERNAME=swage');
});

test('sites connect to their private redis, never the server\'s shared one', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->for($server)->systemUser('example')->create(['private_redis' => true]);

    expect(SiteEnvironment::defaults($site))->toContain("REDIS_HOST=/run/swage-redis-example/redis.sock\nREDIS_PORT=0")
        ->not->toContain('REDIS_PASSWORD');
});

test('new sites get a private redis server only their user can reach', function () {
    $shell = fakeShell();
    $server = Server::factory()->create();

    Livewire::actingAs($server->user)->test('pages::servers.sites', ['server' => $server])
        ->set('domain', 'example.com')
        ->set('system_user', 'example')
        ->set('create_database', false)
        ->call('create')
        ->assertHasNoErrors();

    $site = $server->sites()->sole();

    expect($site->private_redis)->toBeTrue();

    $shell->assertRan('RuntimeDirectoryMode=0700');
    $shell->assertRan('unixsocketperm 700');
    $shell->assertRan('systemctl restart swage-redis@example');
    $shell->assertRan("REDIS_HOST=/run/swage-redis-example/redis.sock\nREDIS_PORT=0");
    $shell->assertRan('usermod -aG example www-data');
    $shell->assertRan('example ALL=(root) NOPASSWD: /usr/local/sbin/swage-reload-php, /usr/local/sbin/swage-restart-daemon');
});

test('removing a site with private redis stops its redis server', function () {
    $shell = fakeShell();
    $site = Site::factory()->systemUser('example')->create(['private_redis' => true]);

    RemoveSite::dispatchSync($site);

    $shell->assertRan('systemctl disable --now swage-redis@example');
    $shell->assertRan('rm -f /etc/swage-redis/example.conf');
});

test('only administrators may add servers on private or reserved addresses', function (string $ip) {
    fakeShell();

    Livewire::actingAs(User::factory()->create())->test('pages::servers.create')
        ->set('name', 'internal')
        ->set('ip_address', $ip)
        ->call('save')
        ->assertHasErrors(['ip_address']);

    Livewire::actingAs(User::factory()->create(['is_admin' => true]))->test('pages::servers.create')
        ->set('name', 'internal')
        ->set('ip_address', $ip)
        ->call('save')
        ->assertHasNoErrors();
})->with(['127.0.0.1', '10.0.0.5', '192.168.1.10', '169.254.169.254', '::1', '100.64.0.1', '192.0.0.8', '198.18.0.1', '64:ff9b::7f00:1', '2002:7f00:1::', '2001::1']);

test('servers are only added in production when swage is served over https', function () {
    fakeShell();
    app()->detectEnvironment(fn () => 'production');
    config(['app.url' => 'http://swage.example.com']);

    Livewire::actingAs(User::factory()->admin()->create())->test('pages::servers.create')
        ->set('name', 'web')
        ->set('ip_address', '203.0.113.10')
        ->call('save')
        ->assertHasErrors(['name']);

    expect(Server::count())->toBe(0);

    config(['app.url' => 'https://swage.example.com']);

    Livewire::actingAs(User::factory()->admin()->create())->test('pages::servers.create')
        ->set('name', 'web')
        ->set('ip_address', '203.0.113.10')
        ->call('save')
        ->assertHasNoErrors();

    expect(Server::count())->toBe(1);
});

test('the certificate email is quoted for the shell', function () {
    $shell = fakeShell()->respondTo('certbot certonly', new ScriptResult(0, 'SWAGE_EXPIRES Jan 1 00:00:00 2027 GMT'));
    $user = User::factory()->create(['email' => "o'brien@example.com"]);
    $site = Site::factory()->for(Server::factory()->for($user))->create();
    config(['swage.certificate_email' => null]);

    ObtainCertificate::dispatchSync(Certificate::factory()->for($site)->create(['status' => ResourceStatus::Pending]));

    $shell->assertRan("--email 'o'\\''brien@example.com'");
});

test('livewire updates from pages a user without two-factor authentication cannot visit are refused', function () {
    $user = User::factory()->create();
    $server = Server::factory()->for($user)->create();

    $page = $this->actingAs($user)->get(route('servers.show', $server))->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $snapshot);

    config(['swage.require_two_factor' => true]);

    $this->withHeaders(['X-Livewire' => 'true', 'Referer' => route('servers.show', $server)])
        ->postJson(route('default-livewire.update'), [
            'components' => [[
                'snapshot' => html_entity_decode($snapshot[1]),
                'updates' => [],
                'calls' => [['method' => 'optimize', 'params' => [], 'metadata' => []]],
            ]],
        ])
        ->assertForbidden();
});

test('livewire updates cannot pair a component from an allowed page with one from another page', function () {
    Queue::fake();
    $user = User::factory()->create();
    $server = Server::factory()->for($user)->create();

    preg_match('/wire:snapshot="([^"]+)"/', $this->actingAs($user)->get(route('servers.show', $server))->getContent(), $serverSnapshot);

    config(['swage.require_two_factor' => true]);

    $securityPage = $this->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $securityPage->getContent(), $securitySnapshot);

    $this->withHeaders(['X-Livewire' => 'true'])
        ->postJson(route('default-livewire.update'), [
            'components' => [
                ['snapshot' => html_entity_decode($securitySnapshot[1]), 'updates' => [], 'calls' => []],
                ['snapshot' => html_entity_decode($serverSnapshot[1]), 'updates' => [], 'calls' => [['method' => 'optimize', 'params' => [], 'metadata' => []]]],
            ],
        ])
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('users without two-factor authentication can still use the security page to set it up', function () {
    config(['swage.require_two_factor' => true]);
    $user = User::factory()->create();

    $page = $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $snapshot);

    $this->withHeaders(['X-Livewire' => 'true'])
        ->postJson(route('default-livewire.update'), [
            'components' => [[
                'snapshot' => html_entity_decode($snapshot[1]),
                'updates' => [],
                'calls' => [['method' => '$refresh', 'params' => [], 'metadata' => []]],
            ]],
        ])
        ->assertOk();
});

test('a deployment can be triggered with the token in an authorization header', function () {
    Queue::fake();
    $site = Site::factory()->withRepository()->create();

    $this->postJson(route('sites.deploy-trigger', ['site' => $site]))->assertNotFound();
    $this->postJson(route('sites.deploy-trigger', ['site' => $site]), [], ['Authorization' => 'Bearer wrong'])->assertNotFound();
    $this->postJson(route('sites.deploy-trigger', ['site' => $site]), [], ['Authorization' => "Bearer {$site->deploy_token}"])->assertAccepted();
    $this->postJson($site->deployTriggerUrl())->assertAccepted();

    Queue::assertPushed(DeploySite::class, 2);
});

test('server secrets are redacted from task and deployment output', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->for($server)->withRepository()->create();
    fakeShell()->respondTo('', new ScriptResult(0, "connected with {$server->database_password}\nSWAGE_COMMIT abc"));

    InstallSite::dispatchSync($site);
    $deployment = $site->deployments()->create(['status' => DeploymentStatus::Pending, 'triggered_by' => 'Test']);
    DeploySite::dispatchSync($deployment);

    expect($server->tasks()->latest('id')->first()->output)->toBe("connected with ********\nSWAGE_COMMIT abc")
        ->and($deployment->fresh()->output)->toContain('connected with ********')
        ->not->toContain($server->database_password);
});

test('tasks older than 90 days are pruned', function () {
    $server = Server::factory()->create();
    $old = $server->tasks()->create(['name' => 'Old', 'status' => TaskStatus::Finished]);
    $old->forceFill(['created_at' => now()->subDays(91)])->save();
    $recent = $server->tasks()->create(['name' => 'Recent', 'status' => TaskStatus::Finished]);

    $this->artisan('model:prune', ['--model' => Task::class])->assertSuccessful();

    expect(Task::find($old->id))->toBeNull()
        ->and(Task::find($recent->id))->not->toBeNull();
});

test('names quoted into shell scripts may not end in a line break', function () {
    $shell = fakeShell();
    $site = Site::factory()->create();

    Livewire::actingAs($site->server->user)->test('pages::sites.show', ['server' => $site->server, 'site' => $site])
        ->set('source', 'custom')
        ->set('repository', "git@git.example.com:team/app.git\n")
        ->set('branch', "main\n")
        ->call('installRepository')
        ->assertHasErrors(['repository', 'branch']);

    Livewire::actingAs($site->server->user)->test('pages::servers.databases', ['server' => $site->server])
        ->call('newUser')
        ->set('username', "root\n")
        ->call('saveUser')
        ->assertHasErrors(['username']);

    expect($site->server->databaseUsers()->exists())->toBeFalse();
    $shell->assertNothingRan();
});
