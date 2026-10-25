<?php

use App\Enums\MemberRole;
use App\Jobs\InstallRepository;
use App\Models\Server;
use App\Models\Site;
use App\Models\SourceControlConnection;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function addServerMember(Server $server, User $user, MemberRole $role): User
{
    $server->members()->attach($user->id, ['role' => $role->value]);

    return $user;
}

function addSiteMember(Site $site, User $user, MemberRole $role): User
{
    $site->members()->attach($user->id, ['role' => $role->value]);

    return $user;
}

test('server members see the server and its sites on their dashboard and sidebar', function () {
    $server = Server::factory()->create(['name' => 'shared-box']);
    $member = addServerMember($server, User::factory()->create(), MemberRole::Viewer);

    $this->actingAs($member)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('shared-box')
        ->assertSee('Shared')
        ->assertSee(route('servers.show', $server));

    $this->get(route('servers.show', $server))->assertOk();
    $this->get(route('servers.sites', $server))->assertOk();
});

test('people who are not members cannot open a server or its sites', function () {
    $site = Site::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('servers.show', $site->server))->assertForbidden();
    $this->get(route('servers.sites.show', [$site->server, $site]))->assertForbidden();
    $this->get(route('dashboard'))->assertDontSee($site->server->name);
});

test('viewers see but cannot change, and never see the environment', function () {
    $site = Site::factory()->create();
    $viewer = addServerMember($site->server, User::factory()->create(), MemberRole::Viewer);

    expect($viewer->can('view', $site))->toBeTrue()
        ->and($viewer->can('update', $site))->toBeFalse()
        ->and($viewer->can('update', $site->server))->toBeFalse()
        ->and($viewer->can('manageMembers', $site->server))->toBeFalse();

    $this->actingAs($viewer)->get(route('servers.sites.show', [$site->server, $site]))->assertOk();
    $this->get(route('servers.sites.settings', [$site->server, $site]))->assertOk()->assertDontSee('Reveal environment');
    $this->get(route('servers.sites.logs', [$site->server, $site]))->assertOk();
    Livewire::actingAs($viewer)->test('pages::sites.environment', ['server' => $site->server, 'site' => $site])->assertForbidden();

    Livewire::actingAs($viewer)->test('pages::sites.show', ['server' => $site->server, 'site' => $site])
        ->call('deploy')
        ->assertDispatched('toast-show');

    expect($site->deployments()->count())->toBe(0);
});

test('managers manage the server and its sites but cannot delete the server', function () {
    $site = Site::factory()->create();
    $manager = addServerMember($site->server, User::factory()->create(), MemberRole::Manager);

    expect($manager->can('update', $site->server))->toBeTrue()
        ->and($manager->can('update', $site))->toBeTrue()
        ->and($manager->can('delete', $site))->toBeTrue()
        ->and($manager->can('manageMembers', $site->server))->toBeTrue()
        ->and($manager->can('delete', $site->server))->toBeFalse();

    $this->actingAs($manager)->get(route('servers.sites.settings', [$site->server, $site]))->assertOk()->assertSee('Reveal environment');
});

test('site members reach only their site, not the rest of its server', function () {
    $site = Site::factory()->create(['domain' => 'theirs.example.com']);
    $other = Site::factory()->for($site->server)->create(['domain' => 'other.example.com']);
    $member = addSiteMember($site, User::factory()->create(), MemberRole::Manager);

    $this->actingAs($member)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Shared sites')
        ->assertSee('theirs.example.com')
        ->assertDontSee('other.example.com');

    $this->get(route('servers.sites.show', [$site->server, $site]))->assertOk()->assertDontSee('href="'.route('servers.sites', $site->server).'"', false);
    $this->get(route('servers.sites.settings', [$site->server, $site]))->assertOk()->assertSee('Reveal environment');
    $this->get(route('servers.sites.show', [$site->server, $other]))->assertForbidden();
    $this->get(route('servers.show', $site->server))->assertForbidden();

    expect($member->can('update', $site))->toBeTrue()
        ->and($member->can('delete', $site))->toBeFalse();
});

test('owners add members by the email of their account, and change and remove them', function () {
    $server = Server::factory()->create();
    $user = User::factory()->create(['email' => 'pat@example.com']);

    $page = Livewire::actingAs($server->user)->test('pages::servers.members', ['server' => $server])
        ->set('email', 'pat@example.com')
        ->set('role', 'manager')
        ->call('addMember')
        ->assertHasNoErrors();

    expect($server->memberRole($user))->toBe(MemberRole::Manager);

    $page->call('changeRole', $user->id, 'viewer');
    expect($server->memberRole($user))->toBe(MemberRole::Viewer);

    $page->call('removeMember', $user->id);
    expect($server->memberRole($user))->toBeNull();
});

test('only existing accounts, not the owner and not twice, can be added', function () {
    $server = Server::factory()->create();
    $member = addServerMember($server, User::factory()->create(), MemberRole::Viewer);

    $page = Livewire::actingAs($server->user)->test('pages::servers.members', ['server' => $server]);

    $page->set('email', 'nobody@example.com')->call('addMember')->assertHasErrors(['email' => 'exists']);
    $page->set('email', $server->user->email)->call('addMember')->assertHasErrors(['email']);
    $page->set('email', $member->email)->call('addMember')->assertHasErrors(['email']);
});

test('managers and administrators manage members, viewers cannot', function () {
    $server = Server::factory()->create();
    $manager = addServerMember($server, User::factory()->create(), MemberRole::Manager);
    $viewer = addServerMember($server, User::factory()->create(), MemberRole::Viewer);
    $admin = User::factory()->admin()->create();
    $newcomer = User::factory()->create();

    Livewire::actingAs($viewer)->test('pages::servers.members', ['server' => $server])
        ->assertDontSee('Add member')
        ->set('email', $newcomer->email)->call('addMember')
        ->assertDispatched('toast-show');
    expect($server->memberRole($newcomer))->toBeNull();

    Livewire::actingAs($manager)->test('pages::servers.members', ['server' => $server])
        ->set('email', $newcomer->email)->call('addMember')->assertHasNoErrors();
    expect($server->memberRole($newcomer))->toBe(MemberRole::Viewer);

    Livewire::actingAs($admin)->test('pages::servers.members', ['server' => $server])
        ->call('removeMember', $newcomer->id);
    expect($server->memberRole($newcomer))->toBeNull();
});

test('a site\'s members tab lists those with access through its server', function () {
    $site = Site::factory()->create();
    $serverMember = addServerMember($site->server, User::factory()->create(['name' => 'Server Sam']), MemberRole::Manager);

    $this->actingAs($site->server->user)->get(route('servers.sites.members', [$site->server, $site]))
        ->assertOk()
        ->assertSee('Server Sam')
        ->assertSee('through the server');

    Livewire::actingAs($serverMember)->test('pages::sites.members', ['server' => $site->server, 'site' => $site])
        ->set('email', User::factory()->create(['email' => 'site-only@example.com'])->email)
        ->call('addMember')
        ->assertHasNoErrors();

    expect($site->members()->pluck('email')->all())->toBe(['site-only@example.com']);
});

test('administrators can open any server, read only', function () {
    $server = Server::factory()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('servers.show', $server))->assertOk();

    expect($admin->can('update', $server))->toBeFalse()
        ->and($admin->can('manageMembers', $server))->toBeTrue();
});

test('members lose access when removed, or when their account or the server is deleted', function () {
    $server = Server::factory()->create();
    $member = addServerMember($server, User::factory()->create(), MemberRole::Viewer);

    $member->delete();

    expect($server->members()->count())->toBe(0);
});

test('viewers never see the server\'s database password or the site\'s deploy trigger url', function () {
    $site = Site::factory()->withRepository()->create();
    $site->server->update(['database_password' => 'db-admin-secret']);
    $viewer = addServerMember($site->server, User::factory()->create(), MemberRole::Viewer);

    $this->actingAs($viewer)->get(route('servers.show', $site->server))->assertOk()->assertDontSee('db-admin-secret');
    $this->get(route('servers.sites.show', [$site->server, $site]))->assertOk()->assertDontSee($site->deployTriggerUrl())->assertDontSee('Delete site');
    $this->get(route('servers.sites.settings', [$site->server, $site]))->assertOk()->assertDontSee('Delete site');

    $this->actingAs($site->server->user)->get(route('servers.sites.show', [$site->server, $site]))->assertSee($site->deployTriggerUrl());
});

test('only the server\'s owner installs a repository through a connected account or changes quick deploy', function () {
    fakeSourceControl('github');
    $site = Site::factory()->withRepository()->create(['quick_deploy' => false]);
    $manager = addServerMember($site->server, User::factory()->create(), MemberRole::Manager);
    SourceControlConnection::factory()->for($manager)->create(['provider' => 'github']);

    expect($manager->can('update', $site))->toBeTrue()
        ->and($manager->can('useSourceControl', $site))->toBeFalse()
        ->and($site->server->user->can('useSourceControl', $site))->toBeTrue();

    $page = Livewire::actingAs($manager)->test('pages::sites.show', ['server' => $site->server, 'site' => $site])
        ->assertSet('connectedProviders', [])
        ->assertSee('Quick deploy is off')
        ->assertSee('Only the server\'s owner can change it.')
        ->set('quick_deploy', true)
        ->assertDispatched('toast-show');

    expect($site->fresh()->quick_deploy)->toBeFalse();

    $page->set('source', 'github')->set('repository', 'acme/other')->set('branch', 'main')->call('installRepository')->assertDispatched('toast-show');

    expect($site->fresh()->repository)->not->toBe('acme/other');
});

test('managers can still install a repository from a clone url', function () {
    Queue::fake();
    $site = Site::factory()->create();
    $manager = addServerMember($site->server, User::factory()->create(), MemberRole::Manager);

    Livewire::actingAs($manager)->test('pages::sites.show', ['server' => $site->server, 'site' => $site])
        ->assertSee('Only the server\'s owner can install from a connected account')
        ->set('source', 'custom')
        ->set('repository', 'git@git.example.com:acme/app.git')
        ->set('branch', 'main')
        ->call('installRepository')
        ->assertHasNoErrors();

    expect($site->fresh()->repository)->toBe('git@git.example.com:acme/app.git');
    Queue::assertPushed(InstallRepository::class);
});
