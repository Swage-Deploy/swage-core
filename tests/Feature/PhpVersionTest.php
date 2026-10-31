<?php

use App\Enums\MemberRole;
use App\Enums\PhpVersion;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Ssh\ScriptResult;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function fakeShellWithSpecs()
{
    return fakeShell()->respondTo('SWAGE_CPU_CORES', new ScriptResult(0, "SWAGE_CPU_CORES 2\nSWAGE_MEMORY_MB 4096\nSWAGE_DISK_GB 80"));
}

test('a php version can be installed on a running server and its sites can switch to it', function () {
    $shell = fakeShellWithSpecs();
    $server = Server::factory()->create(['status' => ServerStatus::Active, 'php_versions' => [PhpVersion::Php85], 'default_php_version' => PhpVersion::Php85]);
    $site = Site::factory()->for($server)->systemUser('example')->create(['php_version' => PhpVersion::Php85]);

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->call('installPhpVersion', '8.3')
        ->assertHasNoErrors();

    $server->refresh();

    expect($server->php_versions->all())->toBe([PhpVersion::Php83, PhpVersion::Php85])
        ->and($server->default_php_version)->toBe(PhpVersion::Php85)
        ->and($server->installing_php)->toBeNull();

    $shell->assertRan('add-apt-repository -y ppa:ondrej/php');
    $shell->assertRan('apt-get install -yq php8.3-fpm php8.3-cli php8.3-common php8.3-mysql');
    expect($shell->lastScript())
        ->toContain('/etc/php/8.3/fpm/pool.d/www.conf')
        ->toContain('/etc/php/8.3/fpm/conf.d/99-swage.ini')
        ->toContain('/etc/php/8.3/cli/conf.d/99-swage.ini')
        ->toContain('systemctl restart php8.3-fpm')
        ->toContain('systemctl restart php8.5-fpm');

    Livewire::actingAs($server->user)->test('pages::sites.settings', ['server' => $server, 'site' => $site])
        ->assertSee('8.3')
        ->set('php_version', '8.3')
        ->call('save')
        ->assertHasNoErrors();

    expect($site->fresh()->php_version)->toBe(PhpVersion::Php83);
    $shell->assertRan("cat > '/etc/php/8.3/fpm/pool.d/example.conf'");
    $shell->assertRan("rm -f '/etc/php/8.5/fpm/pool.d/example.conf'");
    $shell->assertRan('fastcgi_pass unix:/run/php/php8.3-fpm-example.sock;');
});

test('the server page offers the php versions the server does not have', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active, 'php_versions' => [PhpVersion::Php84, PhpVersion::Php85], 'default_php_version' => PhpVersion::Php85]);

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->assertSee('Install PHP 8.3')
        ->assertDontSee('Install PHP 8.4')
        ->assertDontSee('Install PHP 8.5');
});

test('a php version the server already has cannot be installed again', function () {
    Queue::fake();
    $server = Server::factory()->create(['status' => ServerStatus::Active]);

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->call('installPhpVersion', '8.5')
        ->assertHasErrors('version');

    Queue::assertNothingPushed();
});

test('php cannot be installed on a database server', function () {
    Queue::fake();
    $server = Server::factory()->database()->create(['status' => ServerStatus::Active]);

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->call('installPhpVersion', '8.5')
        ->assertStatus(409);

    Queue::assertNothingPushed();
});

test('only one php install runs on a server at a time', function () {
    Queue::fake();
    $server = Server::factory()->create(['status' => ServerStatus::Active, 'installing_php' => '8.4']);

    Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])
        ->call('installPhpVersion', '8.3');

    Queue::assertNothingPushed();
    expect($server->refresh()->installing_php)->toBe('8.4');
});

test('php and a database are not installed on a server at the same time', function () {
    Queue::fake();
    $server = Server::factory()->create(['status' => ServerStatus::Active, 'has_postgres' => false]);

    $page = Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server]);

    $server->update(['installing_database' => 'postgres']);
    $page->call('installPhpVersion', '8.4');
    expect($server->refresh()->installing_php)->toBeNull();

    $server->update(['installing_database' => null, 'installing_php' => '8.4']);
    $page->call('installDatabaseEngine', 'postgres');
    expect($server->refresh()->installing_database)->toBeNull();

    Queue::assertNothingPushed();
});

test('a failed php install leaves the server as it was and can be tried again', function () {
    fakeShell()->respondTo('apt-get install -yq php8.4-fpm', new ScriptResult(100, 'E: Unable to locate package php8.4-fpm'));
    $server = Server::factory()->create(['status' => ServerStatus::Active]);

    rescue(fn () => Livewire::actingAs($server->user)->test('pages::servers.show', ['server' => $server])->call('installPhpVersion', '8.4'), report: false);

    expect($server->refresh())
        ->installing_php->toBeNull()
        ->and($server->php_versions->all())->toBe([PhpVersion::Php85]);
});

test('viewers cannot install php', function () {
    Queue::fake();
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $viewer = User::factory()->create();
    $server->members()->attach($viewer->id, ['role' => MemberRole::Viewer->value]);

    Livewire::actingAs($viewer)->test('pages::servers.show', ['server' => $server])
        ->assertDontSee('Install PHP 8.4')
        ->call('installPhpVersion', '8.4');

    Queue::assertNothingPushed();
    expect($server->refresh()->installing_php)->toBeNull();
});
