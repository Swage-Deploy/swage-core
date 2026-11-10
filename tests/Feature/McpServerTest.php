<?php

use App\Enums\DeploymentStatus;
use App\Enums\TaskStatus;
use App\Mcp\Documentation;
use App\Mcp\Prompts\Troubleshoot;
use App\Mcp\Resources\DocPage;
use App\Mcp\Servers\SwageServer;
use App\Mcp\Tools\GetDeployment;
use App\Mcp\Tools\GetServer;
use App\Mcp\Tools\GetServerMetrics;
use App\Mcp\Tools\GetSite;
use App\Mcp\Tools\GetTask;
use App\Mcp\Tools\ListActivity;
use App\Mcp\Tools\ListDeployments;
use App\Mcp\Tools\ListNotifications;
use App\Mcp\Tools\ListServers;
use App\Mcp\Tools\ListSites;
use App\Mcp\Tools\ReadDoc;
use App\Mcp\Tools\SearchDocs;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use App\Models\Task;
use App\Models\User;
use App\Notifications\RebootRequired;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Livewire\Livewire;

/**
 * Act as the user through an API key with these abilities, as the MCP server's tools see them.
 *
 * @param  list<string>  $abilities
 */
function mcpUser(User $user, array $abilities = ['servers:read', 'sites:read', 'deployments:read', 'certificates:read']): User
{
    return $user->withAccessToken($user->createToken('AI assistant', $abilities)->accessToken);
}

/**
 * Post a JSON-RPC message to the MCP endpoint with an API key.
 *
 * @param  array<string, mixed>  $params
 */
function mcpRequest(?string $key, string $method, array $params = []): TestResponse
{
    return test()->withHeaders(array_filter(['Authorization' => $key ? "Bearer {$key}" : null, 'Accept' => 'application/json, text/event-stream']))
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
}

test('the mcp endpoint needs an api key', function () {
    mcpRequest(null, 'tools/list')->assertUnauthorized();
});

test('the mcp endpoint refuses keys that can write or run commands as root', function (array $abilities) {
    $key = User::factory()->create()->createToken('Too strong', $abilities)->plainTextToken;

    mcpRequest($key, 'tools/list')->assertForbidden()->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'read-only'));
})->with([
    'write' => [['servers:read', 'servers:write']],
    'root' => [['daemons:read', 'daemons:write', 'root']],
    'no abilities' => [[]],
]);

test('a read-only key lists the tools over http', function () {
    $key = User::factory()->create()->createToken('AI assistant', ['servers:read', 'sites:read'])->plainTextToken;

    mcpRequest($key, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']])
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'Swage');

    $tools = collect(mcpRequest($key, 'tools/list')->assertOk()->json('result.tools'));

    expect($tools->pluck('name'))->toContain('list-servers', 'get-server', 'list-sites', 'search-docs', 'read-doc')
        ->not->toContain('list-deployments', 'get-deployment')
        ->and($tools->every(fn (array $tool): bool => $tool['annotations']['readOnlyHint'] === true))->toBeTrue();
});

test('every tool is marked read-only', function () {
    $tools = (fn (): array => $this->tools)->call(new ReflectionClass(SwageServer::class)->newInstanceWithoutConstructor());

    foreach ($tools as $tool) {
        expect(new ReflectionClass($tool)->getAttributes(IsReadOnly::class))->not->toBeEmpty("{$tool} is not marked read-only.");
    }
});

test('tools are only listed for the areas the key can read', function () {
    SwageServer::actingAs(mcpUser(User::factory()->create(), ['sites:read']))->tools()
        ->assertRegistered([ListSites::class, GetSite::class, SearchDocs::class, ReadDoc::class, ListNotifications::class])
        ->assertNotRegistered([ListServers::class, GetServer::class, GetServerMetrics::class, ListActivity::class, GetTask::class, ListDeployments::class, GetDeployment::class]);
});

test('no tool connects to a server', function () {
    $shell = fakeShell();
    $server = Server::factory()->create();
    $site = Site::factory()->for($server)->create();
    $deployment = Deployment::factory()->for($site)->create();
    $task = Task::factory()->for($server)->create();
    $mcp = SwageServer::actingAs(mcpUser($server->user));

    $mcp->tool(ListServers::class)->assertOk();
    $mcp->tool(GetServer::class, ['server_id' => $server->id])->assertOk();
    $mcp->tool(GetServerMetrics::class, ['server_id' => $server->id])->assertOk();
    $mcp->tool(ListActivity::class, ['server_id' => $server->id])->assertOk();
    $mcp->tool(GetTask::class, ['task_id' => $task->id])->assertOk();
    $mcp->tool(ListSites::class)->assertOk();
    $mcp->tool(GetSite::class, ['site_id' => $site->id])->assertOk();
    $mcp->tool(ListDeployments::class, ['site_id' => $site->id])->assertOk();
    $mcp->tool(GetDeployment::class, ['deployment_id' => $deployment->id])->assertOk();
    $mcp->tool(ListNotifications::class)->assertOk();

    expect($shell->ran)->toBe([]);
});

test('servers list only those the key\'s user can see, with their usage', function () {
    $server = Server::factory()->create(['name' => 'web-1']);
    $hidden = Server::factory()->create(['name' => 'someone-elses']);
    $server->metrics()->forceCreate(['cpu_percent' => 37.5, 'load_1' => 0.4, 'load_5' => 0.3, 'load_15' => 0.2, 'memory_total' => 100, 'memory_used' => 40, 'swap_total' => 0, 'swap_used' => 0, 'disk_total' => 100, 'disk_used' => 90, 'uptime_seconds' => 60]);

    SwageServer::actingAs(mcpUser($server->user))->tool(ListServers::class)
        ->assertOk()
        ->assertSee(['web-1', '"cpu_percent":37.5', '"disk_percent":90'])
        ->assertDontSee('someone-elses');

    SwageServer::actingAs(mcpUser($server->user))->tool(GetServer::class, ['server_id' => $hidden->id])
        ->assertHasErrors(["There is no server with ID {$hidden->id} that this key can see."]);
});

test('a server shows why it needs a reboot and its recent failed tasks', function () {
    $server = Server::factory()->create(['reboot_required_at' => now(), 'reboot_required_packages' => ['linux-image-6.8.0-90-generic']]);
    Task::factory()->for($server)->create(['name' => 'Install Redis', 'status' => TaskStatus::Failed]);

    SwageServer::actingAs(mcpUser($server->user))->tool(GetServer::class, ['server_id' => $server->id])
        ->assertOk()
        ->assertSee(['linux-image-6.8.0-90-generic', 'Install Redis'])
        ->assertDontSee('provision_command');
});

test('a site never includes its secrets', function () {
    $site = Site::factory()->create([
        'deploy_token' => 'deploy-token-secret',
        'webhook_secret' => 'webhook-secret-value',
        'deploy_key_private' => 'PRIVATE-KEY-MATERIAL',
        'deploy_script' => 'php artisan migrate --force',
    ]);

    SwageServer::actingAs(mcpUser($site->server->user))->tool(GetSite::class, ['site_id' => $site->id])
        ->assertOk()
        ->assertSee([$site->domain, 'php artisan migrate --force'])
        ->assertDontSee(['deploy-token-secret', 'webhook-secret-value', 'PRIVATE-KEY-MATERIAL', 'deploy_trigger_url']);
});

test('a site shared with a member is visible without its server', function () {
    $site = Site::factory()->create();
    $member = User::factory()->create();
    $site->members()->attach($member, ['role' => 'viewer']);

    SwageServer::actingAs(mcpUser($member))->tool(ListSites::class)->assertOk()->assertSee($site->domain);
    SwageServer::actingAs(mcpUser($member))->tool(GetServer::class, ['server_id' => $site->server_id])->assertHasErrors();
});

test('a failed deployment\'s output is read from its end', function () {
    $deployment = Deployment::factory()->create([
        'status' => DeploymentStatus::Failed,
        'output' => str_repeat("composer install line\n", 2000)."SQLSTATE[HY000] [2002] Connection refused\n",
    ]);

    SwageServer::actingAs(mcpUser($deployment->site->server->user))->tool(GetDeployment::class, ['deployment_id' => $deployment->id])
        ->assertOk()
        ->assertSee(['[earlier output cut]', 'SQLSTATE[HY000] [2002] Connection refused']);
});

test('deployments and tasks of servers the user cannot see are not found', function () {
    $deployment = Deployment::factory()->create();
    $task = Task::factory()->create();
    $mcp = SwageServer::actingAs(mcpUser(User::factory()->create()));

    $mcp->tool(GetDeployment::class, ['deployment_id' => $deployment->id])->assertHasErrors();
    $mcp->tool(GetTask::class, ['task_id' => $task->id])->assertHasErrors();
});

test('activity can be filtered to failed tasks', function () {
    $server = Server::factory()->create();
    Task::factory()->for($server)->create(['name' => 'Obtain certificate', 'status' => TaskStatus::Failed]);
    Task::factory()->for($server)->create(['name' => 'Create database', 'status' => TaskStatus::Finished]);

    SwageServer::actingAs(mcpUser($server->user))->tool(ListActivity::class, ['server_id' => $server->id, 'status' => 'failed'])
        ->assertOk()
        ->assertSee('Obtain certificate')
        ->assertDontSee('Create database');
});

test('notifications are listed without being marked read', function () {
    $server = Server::factory()->create();
    $server->user->notify(new RebootRequired($server));

    SwageServer::actingAs(mcpUser($server->user))->tool(ListNotifications::class, ['unread_only' => true])
        ->assertOk()
        ->assertSee($server->name);

    expect($server->user->unreadNotifications()->count())->toBe(1);
});

test('the documentation is bundled with the release', function () {
    $docs = app(Documentation::class);

    expect($docs->pages()->keys())->toContain('installation', 'servers', 'deployments', 'troubleshooting')
        ->and($docs->read('servers'))->toContain('# Servers');
});

test('the documentation can be searched and read', function () {
    $mcp = SwageServer::actingAs(mcpUser(User::factory()->create()));

    $mcp->tool(SearchDocs::class, ['query' => 'certificate renew certbot'])->assertOk()->assertSee('https://swagedeploy.app/docs/');
    $mcp->tool(ReadDoc::class, ['page' => 'deployments'])->assertOk()->assertSee(['# Deployments', 'Published at https://swagedeploy.app/docs/deployments']);
    $mcp->tool(ReadDoc::class, ['page' => '../.env'])->assertHasErrors();
    $mcp->resource(DocPage::class, ['page' => 'servers'])->assertOk()->assertSee('# Servers');
});

test('the troubleshooting prompt keeps the assistant to reading', function () {
    SwageServer::actingAs(mcpUser(User::factory()->create()))->prompt(Troubleshoot::class, ['problem' => 'the last deploy failed'])
        ->assertOk()
        ->assertSee(['the last deploy failed', 'You can only read.']);
});

test('the docs are synced from a swagedeployapp checkout', function () {
    $source = storage_path('framework/testing/docs-source');
    $target = resource_path('docs');
    $backup = storage_path('framework/testing/docs-backup');

    File::copyDirectory($target, $backup);
    File::ensureDirectoryExists("{$source}/config");
    File::ensureDirectoryExists("{$source}/resources/docs");
    File::put("{$source}/config/docs.php", "<?php return ['sections' => ['Start' => ['intro' => 'Introduction', 'missing' => 'Missing']]];");
    File::put("{$source}/resources/docs/intro.md", "# Introduction\n");

    try {
        $this->artisan('swage:sync-docs', ['source' => $source])->assertSuccessful();

        expect(File::glob("{$target}/*.md"))->toBe(["{$target}/intro.md"])
            ->and(json_decode(File::get("{$target}/index.json"), true))->toBe(['sections' => [['title' => 'Start', 'pages' => [['slug' => 'intro', 'title' => 'Introduction']]]]]);
    } finally {
        File::deleteDirectory($target);
        File::moveDirectory($backup, $target);
        File::deleteDirectory($source);
    }
});

test('the api keys page creates a read-only key for an ai assistant and shows how to connect it', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.api-keys')
        ->call('add')
        ->call('aiAssistant')
        ->call('create')
        ->assertSet('plainTextKeyIsReadOnly', true)
        ->assertSee('claude mcp add --transport http swage '.route('mcp'));

    expect($user->tokens()->sole())
        ->name->toBe('AI assistant')
        ->abilities->toBe(['certificates:read', 'deployments:read', 'servers:read', 'sites:read']);
});

test('keys that can write are not offered for ai assistants', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::settings.api-keys')
        ->call('add')
        ->set('name', 'CI')
        ->call('fullAccess')
        ->call('create')
        ->assertSet('plainTextKeyIsReadOnly', false)
        ->assertDontSee('claude mcp add');
});
