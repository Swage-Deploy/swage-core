<?php

use App\Enums\ApiAbility;
use App\Enums\DatabaseEngine;
use App\Enums\MemberRole;
use App\Enums\ResourceStatus;
use App\Jobs\CreateDatabase;
use App\Jobs\DeploySite;
use App\Jobs\InstallDaemon;
use App\Jobs\InstallRepository;
use App\Jobs\InstallScheduledJob;
use App\Jobs\InstallSite;
use App\Jobs\ObtainCertificate;
use App\Jobs\RebootServer;
use App\Jobs\RemoveSite;
use App\Jobs\RunSiteCommand;
use App\Jobs\SyncDatabaseUser;
use App\Models\Database;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use App\Models\Task;
use App\Models\User;
use App\Ssh\ScriptResult;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

/**
 * Send API requests with a new key of the user's, able to do everything unless given abilities.
 *
 * @param  list<string>|null  $abilities
 */
function withApiKey(User $user, ?array $abilities = null): User
{
    // The guard remembers who it authenticated, so a key given part way through a test is read afresh.
    app('auth')->forgetGuards();

    test()->withToken($user->createToken('test', $abilities ?? ApiAbility::all())->plainTextToken);

    return $user;
}

function apiMember(Server|Site $resource, MemberRole $role): User
{
    $user = User::factory()->create();
    $resource->members()->attach($user->id, ['role' => $role->value]);

    return $user;
}

test('requests without a valid key are refused', function () {
    $this->getJson('/api/v1/servers')->assertUnauthorized();
    $this->withToken('1|swage_not-a-key')->getJson('/api/v1/servers')->assertUnauthorized();
});

test('session sign-ins do not reach the API', function () {
    $this->actingAs(User::factory()->create())->getJson('/api/v1/servers')->assertUnauthorized();
});

test('expired keys stop working', function () {
    $user = User::factory()->create();
    $token = $user->createToken('old', ApiAbility::all(), now()->subMinute())->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/user')->assertUnauthorized();
});

test('the user endpoint describes the key', function () {
    $user = withApiKey(User::factory()->create(['name' => 'Ada']), ['servers:read']);

    $this->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.name', 'Ada')
        ->assertJsonPath('data.api_key.abilities', ['servers:read']);

    expect($user->tokens()->sole()->last_used_at)->not->toBeNull();
});

test('a key without an ability is refused that area', function () {
    $server = Server::factory()->create();
    withApiKey($server->user, ['sites:read']);

    $this->getJson('/api/v1/servers')->assertForbidden();
    $this->getJson("/api/v1/servers/{$server->id}/sites")->assertOk();
    $this->postJson("/api/v1/servers/{$server->id}/reboot")->assertForbidden();
});

test('servers are listed as on the dashboard, without their secrets', function () {
    $user = User::factory()->create();
    $own = Server::factory()->for($user)->create(['name' => 'own']);
    $shared = Server::factory()->create(['name' => 'shared']);
    $shared->members()->attach($user->id, ['role' => MemberRole::Viewer->value]);
    Server::factory()->create(['name' => 'someone-elses']);

    withApiKey($user, ['servers:read']);

    $response = $this->getJson('/api/v1/servers')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['own', 'shared'])
        ->and($response->getContent())->not->toContain('fake-private-key')
        ->not->toContain($own->provision_token)
        ->not->toContain($own->database_password);

    $response->assertJsonPath('data.0.can.update', true)->assertJsonPath('data.1.can.update', false);
});

test('administrators list every server', function () {
    Server::factory()->count(2)->create();

    withApiKey(User::factory()->admin()->create(), ['servers:read']);

    $this->getJson('/api/v1/servers')->assertOk()->assertJsonCount(2, 'data');
});

test('a server can be connected through the API, with the command that provisions it', function () {
    $user = withApiKey(User::factory()->create());

    $response = $this->postJson('/api/v1/servers', [
        'name' => 'api-box',
        'ip_address' => '203.0.113.10',
        'php_versions' => ['8.4', '8.5'],
        'database' => 'postgres',
    ])->assertCreated();

    $server = $user->servers()->sole();

    expect($server->name)->toBe('api-box')
        ->and($server->default_php_version->value)->toBe('8.5')
        ->and($server->has_postgres)->toBeTrue()
        ->and($server->has_redis)->toBeTrue();

    $response->assertJsonPath('data.provision_command', $server->provisionCommand());
});

test('connecting a server through the API is validated like the form', function () {
    withApiKey(User::factory()->create());

    $this->postJson('/api/v1/servers', ['name' => 'bad name', 'ip_address' => '10.0.0.5'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'ip_address']);
});

test('servers that are not the user\'s are not found', function () {
    $server = Server::factory()->create();

    withApiKey(User::factory()->create());

    $this->getJson("/api/v1/servers/{$server->id}")->assertForbidden();
});

test('viewers can read a server but not change it', function () {
    Queue::fake();
    $server = Server::factory()->create();
    withApiKey(apiMember($server, MemberRole::Viewer));

    $this->getJson("/api/v1/servers/{$server->id}")->assertOk()->assertJsonMissingPath('data.provision_command');
    $this->postJson("/api/v1/servers/{$server->id}/reboot")->assertForbidden();
    $this->deleteJson("/api/v1/servers/{$server->id}")->assertForbidden();

    Queue::assertNothingPushed();
});

test('managers can reboot a server but only its owner can delete it', function () {
    Queue::fake();
    $server = Server::factory()->create();
    withApiKey(apiMember($server, MemberRole::Manager));

    $this->postJson("/api/v1/servers/{$server->id}/reboot")->assertAccepted();
    Queue::assertPushed(RebootServer::class);

    $this->deleteJson("/api/v1/servers/{$server->id}")->assertForbidden();
});

test('the owner can delete a server', function () {
    Queue::fake();
    $server = Server::factory()->create();
    withApiKey($server->user);

    $this->deleteJson("/api/v1/servers/{$server->id}")->assertNoContent();

    expect($server->fresh())->toBeNull();
});

test('a server\'s activity lists its tasks, and each one has its output', function () {
    $server = Server::factory()->create();
    $task = Task::factory()->for($server)->create(['name' => 'Installing site', 'output' => 'done']);
    withApiKey($server->user, ['servers:read']);

    $this->getJson("/api/v1/servers/{$server->id}/activity")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Installing site')
        ->assertJsonMissingPath('data.0.output');

    $this->getJson("/api/v1/servers/{$server->id}/activity/{$task->id}")->assertOk()->assertJsonPath('data.output', 'done');

    $other = Task::factory()->create();
    $this->getJson("/api/v1/servers/{$server->id}/activity/{$other->id}")->assertNotFound();
});

test('a site can be created through the API', function () {
    Bus::fake();
    $server = Server::factory()->create();
    withApiKey($server->user);

    $this->postJson("/api/v1/servers/{$server->id}/sites", [
        'domain' => 'Example.com',
        'aliases' => ['www.example.com'],
        'system_user' => 'example',
        'create_database' => true,
        'database' => 'example',
    ])->assertCreated()->assertJsonPath('data.domain', 'example.com')->assertJsonPath('data.system_user', 'example');

    $site = $server->sites()->sole();

    expect($site->aliases)->toBe(['www.example.com'])
        ->and($site->zero_downtime)->toBeTrue()
        ->and($server->databases()->sole()->name)->toBe('example');

    Bus::assertChained([CreateDatabase::class, SyncDatabaseUser::class, InstallSite::class, InstallScheduledJob::class]);
});

test('sites created through the API are always isolated, named after their domain unless a user is given', function () {
    Bus::fake();
    $server = Server::factory()->create();
    withApiKey($server->user);

    $this->postJson("/api/v1/servers/{$server->id}/sites", ['domain' => 'shop.example.com', 'isolated' => false])
        ->assertCreated()
        ->assertJsonPath('data.isolated', true)
        ->assertJsonPath('data.system_user', 'shop_example');
});

test('creating a site through the API is validated like the form', function () {
    Bus::fake();
    $server = Server::factory()->create();
    Site::factory()->for($server)->create(['domain' => 'taken.com']);
    withApiKey($server->user);

    $this->postJson("/api/v1/servers/{$server->id}/sites", ['domain' => 'taken.com', 'aliases' => 'not_a_domain'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('domain');

    $this->postJson("/api/v1/servers/{$server->id}/sites", ['domain' => 'fresh.com', 'aliases' => 'bad alias!'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('aliases');
});

test('every site the user can open is listed, including ones shared on their own', function () {
    $user = User::factory()->create();
    $own = Site::factory()->for(Server::factory()->for($user))->create(['domain' => 'own.com']);
    $shared = Site::factory()->create(['domain' => 'shared.com']);
    $shared->members()->attach($user->id, ['role' => MemberRole::Viewer->value]);
    Site::factory()->create(['domain' => 'hidden.com']);

    withApiKey($user, ['sites:read']);

    expect(collect($this->getJson('/api/v1/sites')->assertOk()->json('data'))->pluck('domain')->all())->toBe(['own.com', 'shared.com']);
});

test('site members reach their site but not the rest of its server', function () {
    $site = Site::factory()->create();
    withApiKey(apiMember($site, MemberRole::Viewer));

    $this->getJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}")->assertOk()->assertJsonMissingPath('data.deploy_trigger_url');
    $this->getJson("/api/v1/servers/{$site->server_id}")->assertForbidden();
    $this->getJson("/api/v1/servers/{$site->server_id}/sites")->assertForbidden();
});

test('a site on another server is not found under this one', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->for(Server::factory()->for($server->user))->create();
    withApiKey($server->user);

    $this->getJson("/api/v1/servers/{$server->id}/sites/{$site->id}")->assertNotFound();
    $this->getJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}")->assertOk();
});

test('managers see the deploy trigger URL and can change site settings', function () {
    Queue::fake();
    $site = Site::factory()->create(['aliases' => ['www.example.com']]);
    withApiKey(apiMember($site, MemberRole::Manager));

    $this->getJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}")->assertJsonPath('data.deploy_trigger_url', $site->deployTriggerUrl());

    $this->patchJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}", ['web_directory' => '/web'])->assertOk();

    expect($site->fresh())->web_directory->toBe('/web')->aliases->toBe(['www.example.com']);
});

test('deleting a site needs someone who can manage its server', function () {
    Queue::fake();
    $site = Site::factory()->create();
    withApiKey(apiMember($site, MemberRole::Manager));

    $this->deleteJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}")->assertForbidden();

    withApiKey($site->server->user);

    $this->deleteJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}")->assertAccepted();
    Queue::assertPushed(RemoveSite::class);
    expect($site->fresh()->status)->toBe(ResourceStatus::Removing);
});

test('a custom repository can be installed, but only the owner installs from a provider', function () {
    Queue::fake();
    fakeSourceControl();
    $site = Site::factory()->create();
    withApiKey(apiMember($site, MemberRole::Manager));

    $this->putJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}/repository", ['source' => 'github', 'repository' => 'acme/app', 'branch' => 'main'])->assertForbidden();

    $this->putJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}/repository", ['repository' => 'git@git.example.com:acme/app.git', 'branch' => 'main'])
        ->assertOk()
        ->assertJsonPath('data.repository.source', 'custom');

    Queue::assertPushed(InstallRepository::class);

    $this->putJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}/repository", ['repository' => 'git@host:app.git; rm -rf /', 'branch' => 'main'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('repository');
});

test('deployments can be triggered, listed and read', function () {
    Queue::fake();
    $site = Site::factory()->withRepository()->create();
    $user = withApiKey($site->server->user, ['deployments:write', 'deployments:read']);

    $this->postJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}/deployments")
        ->assertAccepted()
        ->assertJsonPath('data.triggered_by', $user->name);

    Queue::assertPushed(DeploySite::class);

    $deployment = Deployment::sole();
    $deployment->update(['output' => 'Deployed.']);

    $this->getJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}/deployments")->assertOk()->assertJsonMissingPath('data.0.output');
    $this->getJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}/deployments/{$deployment->id}")->assertJsonPath('data.output', 'Deployed.');
});

test('a site without a repository cannot be deployed', function () {
    $site = Site::factory()->create();
    withApiKey($site->server->user);

    $this->postJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}/deployments")->assertConflict();
});

test('the deploy script can be read and changed', function () {
    $site = Site::factory()->create();
    withApiKey($site->server->user);

    $this->putJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}/deploy-script", ['deploy_script' => '$CREATE_RELEASE()'])
        ->assertOk()
        ->assertJsonPath('data.deploy_script', '$CREATE_RELEASE()');

    expect($site->fresh()->deploy_script)->toBe('$CREATE_RELEASE()');
});

test('the environment needs its own ability and someone who can manage the site', function () {
    $site = Site::factory()->create();
    fakeShell()->respondTo('.env', new ScriptResult(0, "APP_KEY=base64:secret\n"));

    withApiKey($site->server->user, ['sites:read']);
    $this->getJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}/environment")->assertForbidden();

    withApiKey(apiMember($site, MemberRole::Viewer), ['environment:read']);
    $this->getJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}/environment")->assertForbidden();

    withApiKey($site->server->user, ['environment:read']);
    $this->getJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}/environment")->assertOk()->assertJsonPath('data.environment', "APP_KEY=base64:secret\n");
});

test('the environment can be replaced', function () {
    $site = Site::factory()->create();
    $shell = fakeShell();
    withApiKey($site->server->user, ['environment:write']);

    $this->putJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}/environment", ['environment' => "APP_ENV=production\n"])->assertOk();

    $shell->assertRan('APP_ENV=production');
});

test('certificates are requested for the site and its aliases', function () {
    Queue::fake();
    $site = Site::factory()->create(['domain' => 'example.com', 'aliases' => ['www.example.com', '*.example.com']]);
    withApiKey($site->server->user, ['certificates:write']);

    $this->postJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}/certificate")->assertAccepted();

    expect($site->certificate()->sole()->domains)->toBe(['example.com', 'www.example.com']);
    Queue::assertPushed(ObtainCertificate::class);
});

test('artisan commands are run, and refused the same way as on the Commands tab', function () {
    Queue::fake();
    $site = Site::factory()->withRepository()->create();
    withApiKey($site->server->user, ['commands:write', 'commands:read']);

    $this->postJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}/commands", ['command' => 'migrate --force'])
        ->assertAccepted()
        ->assertJsonPath('data.command', 'migrate --force');

    Queue::assertPushed(RunSiteCommand::class);

    $this->postJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}/commands", ['command' => 'tinker'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('command');

    $this->getJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}/commands")->assertOk()->assertJsonCount(1, 'data');
});

test('viewers cannot see or run commands', function () {
    $site = Site::factory()->withRepository()->create();
    withApiKey(apiMember($site, MemberRole::Viewer));

    $this->getJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}/commands")->assertForbidden();
    $this->postJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}/commands", ['command' => 'about'])->assertForbidden();
});

test('queue workers and the scheduler can be managed', function () {
    Queue::fake();
    $site = Site::factory()->create();
    withApiKey($site->server->user, ['daemons:write', 'daemons:read']);

    $this->postJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}/workers", ['queue' => 'high,default', 'processes' => 2])
        ->assertCreated()
        ->assertJsonPath('data.processes', 2);

    expect($site->daemons()->sole()->command)->toContain('--queue=high,default');
    Queue::assertPushed(InstallDaemon::class);

    $this->putJson("/api/v1/servers/{$site->server_id}/sites/{$site->id}/scheduler", ['enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);
    Queue::assertPushed(InstallScheduledJob::class);
});

test('daemons and scheduled jobs are validated like the forms', function () {
    Queue::fake();
    $server = Server::factory()->create();
    withApiKey($server->user, ['daemons:write']);

    $this->postJson("/api/v1/servers/{$server->id}/daemons", ['command' => 'php artisan reverb:start', 'user' => 'nobody'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user');

    $this->postJson("/api/v1/servers/{$server->id}/daemons", ['command' => 'php artisan reverb:start'])->assertCreated();

    $this->postJson("/api/v1/servers/{$server->id}/scheduled-jobs", ['command' => 'php backup.php', 'frequency' => 'not cron'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('frequency');

    $this->postJson("/api/v1/servers/{$server->id}/scheduled-jobs", ['command' => 'php backup.php', 'frequency' => '*/5 * * * *'])
        ->assertCreated()
        ->assertJsonPath('data.frequency', '*/5 * * * *');
});

test('databases and their users can be created, without passwords coming back', function () {
    Queue::fake();
    $server = Server::factory()->create();
    withApiKey($server->user, ['databases:write', 'databases:read']);

    $this->postJson("/api/v1/servers/{$server->id}/databases", ['name' => 'shop'])->assertCreated()->assertJsonPath('data.engine', 'mysql');
    $database = Database::sole();

    $response = $this->postJson("/api/v1/servers/{$server->id}/database-users", [
        'username' => 'shop',
        'password' => 'correct-horse-battery',
        'databases' => [$database->id],
    ])->assertCreated()->assertJsonPath('data.databases', [$database->id]);

    expect($response->getContent())->not->toContain('correct-horse-battery');

    $user = $server->databaseUsers()->sole();

    $this->patchJson("/api/v1/servers/{$server->id}/database-users/{$user->id}", ['databases' => []])->assertOk()->assertJsonPath('data.databases', []);
    expect($user->fresh()->password)->toBe('correct-horse-battery');

    $this->postJson("/api/v1/servers/{$server->id}/databases", ['name' => 'mysql'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson("/api/v1/servers/{$server->id}/databases", ['name' => 'other', 'engine' => DatabaseEngine::Postgres->value])->assertUnprocessable()->assertJsonValidationErrors('engine');
});

test('only a key given the root ability can make daemons and scheduled jobs run as root', function () {
    Queue::fake();
    $server = Server::factory()->create();
    withApiKey($server->user, ['daemons:read', 'daemons:write']);

    $this->postJson("/api/v1/servers/{$server->id}/daemons", ['command' => 'php worker.php', 'user' => 'root'])
        ->assertForbidden()
        ->assertJsonPath('message', 'This API key cannot run commands as root. Create a key with "Run commands as root" for that.');
    $this->postJson("/api/v1/servers/{$server->id}/scheduled-jobs", ['command' => 'php backup.php', 'user' => 'root'])->assertForbidden();
    $this->postJson("/api/v1/servers/{$server->id}/daemons", ['command' => 'php worker.php', 'user' => 'swage'])->assertCreated();

    expect($server->daemons()->where('user', 'root')->exists())->toBeFalse()
        ->and($server->scheduledJobs()->count())->toBe(0);

    withApiKey($server->user, ['daemons:read', 'daemons:write', 'root']);

    $this->postJson("/api/v1/servers/{$server->id}/daemons", ['command' => 'php worker.php', 'user' => 'root'])->assertCreated();
    $this->postJson("/api/v1/servers/{$server->id}/scheduled-jobs", ['command' => 'php backup.php', 'user' => 'root'])->assertCreated();
});

test('full access does not include running commands as root', function () {
    expect(ApiAbility::all())->not->toContain('root')->toContain('daemons:write');
});
