<?php

use App\Deployments\DeployScript;
use App\Enums\DeploymentStatus;
use App\Enums\ResourceStatus;
use App\Jobs\DeploySite;
use App\Jobs\InstallRepository;
use App\Models\Daemon;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\SourceControl\LinksToCommits;
use App\SourceControl\NotConnectedException;
use App\SourceControl\SourceControlProviders;
use App\Ssh\ScriptResult;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fakes\FakeSourceControlProvider;

test('the deploy script expands macros into release commands', function () {
    $site = Site::factory()->withRepository()->create();
    $worker = Daemon::factory()->for($site->server)->create(['site_id' => $site->id]);

    $script = DeployScript::render($site);

    expect($script)
        ->not->toContain('$CREATE_RELEASE()')
        ->not->toContain('$ACTIVATE_RELEASE()')
        ->not->toContain('$RESTART_QUEUES()')
        ->toContain("git clone --depth 1 --branch \"\$SWAGE_SITE_BRANCH\" 'git@github.com:{$site->repository}.git'")
        ->toContain('mv -Tf "$SWAGE_SITE_PATH/current-next" "$SWAGE_SITE_PATH/current"')
        ->toContain("swage_restart_daemon {$worker->id}\n")
        ->toContain('composer install --no-dev')
        ->toContain('php artisan cache:clear');
});

test('a successful deployment records its commit and output', function () {
    $shell = fakeShell()->respondTo('git clone', new ScriptResult(0, "Cloning\nSWAGE_COMMIT abc1234def\nSWAGE_AUTHOR Taylor\nSWAGE_MESSAGE Fix the thing\nDone"));
    $site = Site::factory()->withRepository()->systemUser('example')->create();
    $deployment = $site->deployments()->create(['status' => DeploymentStatus::Pending, 'triggered_by' => 'Test']);

    DeploySite::dispatchSync($deployment);

    expect($deployment->fresh())
        ->status->toBe(DeploymentStatus::Finished)
        ->commit_hash->toBe('abc1234def')
        ->commit_author->toBe('Taylor')
        ->commit_message->toBe('Fix the thing')
        ->output->toContain('Done')
        ->finished_at->not->toBeNull();

    expect($shell->ran[0]['as'])->toBe('example');
});

test('a failed deployment is marked as failed', function () {
    fakeShell()->respondTo('git clone', new ScriptResult(1, 'composer install failed'));
    $deployment = Deployment::factory()->for(Site::factory()->withRepository())->create(['status' => DeploymentStatus::Pending]);

    DeploySite::dispatchSync($deployment);

    expect($deployment->fresh())
        ->status->toBe(DeploymentStatus::Failed)
        ->output->toContain('composer install failed');
});

test('deployments can be started from the site page', function () {
    Queue::fake();
    $site = Site::factory()->withRepository()->create();

    Livewire::actingAs($site->server->user)->test('pages::sites.show', ['server' => $site->server, 'site' => $site])
        ->call('deploy');

    expect($site->deployments()->sole()->status)->toBe(DeploymentStatus::Pending);
    Queue::assertPushed(DeploySite::class);
});

test('sites without a repository cannot be deployed', function () {
    Queue::fake();
    $site = Site::factory()->create();

    Livewire::actingAs($site->server->user)->test('pages::sites.show', ['server' => $site->server, 'site' => $site])
        ->call('deploy');

    expect($site->deployments()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('the deploy trigger url requires the site token', function () {
    Queue::fake();
    $site = Site::factory()->withRepository()->create();

    $this->post(route('sites.deploy-trigger', ['site' => $site, 'token' => 'wrong']))->assertNotFound();
    Queue::assertNothingPushed();

    $this->post($site->deployTriggerUrl())->assertAccepted();
    Queue::assertPushed(DeploySite::class);
});

test('a provider client refuses users without a connected account', function () {
    fakeSourceControl();

    expect(fn () => app(SourceControlProviders::class)->clientFor(User::factory()->create(), 'github'))
        ->toThrow(NotConnectedException::class);
});

test('a repository cannot be installed without a connected github account', function () {
    Queue::fake();
    $site = Site::factory()->create();

    Livewire::actingAs($site->server->user)->test('pages::sites.show', ['server' => $site->server, 'site' => $site])
        ->assertSet('source', 'custom')
        ->set('source', 'github')
        ->set('repository', 'acme/app')
        ->set('branch', 'main')
        ->call('installRepository')
        ->assertHasErrors(['source']);

    expect($site->fresh()->repository)->toBeNull();
    Queue::assertNotPushed(InstallRepository::class);
});

test('quick deploy cannot be turned on without a connected account on the site\'s provider', function () {
    fakeSourceControl();
    Http::fake();
    $site = Site::factory()->withRepository()->create();

    Livewire::actingAs($site->server->user)->test('pages::sites.show', ['server' => $site->server, 'site' => $site])
        ->set('quick_deploy', true)
        ->assertSet('quick_deploy', false);

    expect($site->fresh()->quick_deploy)->toBeFalse();
    Http::assertNothingSent();
});

test('deployments are not triggered from outside swage while a site cannot deploy', function (array $attributes) {
    Queue::fake();
    fakeSourceControl();
    $site = Site::factory()->withRepository()->create(['quick_deploy' => true, 'webhook_secret' => 'secret', ...$attributes]);

    $this->post($site->deployTriggerUrl())->assertStatus(409);
    $this->postJson(route('webhooks.source-control', ['github', $site]), ['event' => 'push', 'branch' => 'main'], ['X-Fake-Token' => 'secret'])
        ->assertStatus(409);

    Queue::assertNothingPushed();
})->with([
    'being removed' => [['status' => ResourceStatus::Removing]],
    'deploy key not installed yet' => [['deploy_key_id' => null]],
]);

test('a site busy updating its config still accepts deployments', function () {
    Queue::fake();
    $site = Site::factory()->withRepository()->create(['status' => ResourceStatus::Installing]);

    $this->post($site->deployTriggerUrl())->assertAccepted();
    Queue::assertPushed(DeploySite::class);
});

test('a queued deployment of a site that is being removed does not run', function () {
    $shell = fakeShell();
    $site = Site::factory()->withRepository()->create(['status' => ResourceStatus::Removing]);
    $deployment = Deployment::factory()->for($site)->create(['status' => DeploymentStatus::Pending]);

    DeploySite::dispatchSync($deployment);

    expect($deployment->fresh())
        ->status->toBe(DeploymentStatus::Failed)
        ->output->toContain('being removed');
    $shell->assertNothingRan();
});

test('old deployments are pruned, except each site\'s latest', function () {
    $site = Site::factory()->create();
    $quietSite = Site::factory()->create();
    $old = Deployment::factory()->for($site)->create(['created_at' => now()->subDays(Deployment::RETENTION_DAYS + 1)]);
    $recent = Deployment::factory()->for($site)->create();
    $onlyDeployment = Deployment::factory()->for($quietSite)->create(['created_at' => now()->subDays(400)]);

    $this->artisan('model:prune', ['--model' => Deployment::class])->assertSuccessful();

    expect(Deployment::pluck('id')->sort()->values()->all())->toBe(collect([$recent->id, $onlyDeployment->id])->sort()->values()->all())
        ->and($old->fresh())->toBeNull();
});

test('deploy tokens and webhook secrets are stored encrypted', function () {
    $site = Site::factory()->create(['deploy_token' => 'plain-token', 'webhook_secret' => 'plain-secret']);

    $row = DB::table('sites')->where('id', $site->id)->first();

    expect($row->deploy_token)->not->toContain('plain-token')
        ->and(Crypt::decryptString($row->deploy_token))->toBe('plain-token')
        ->and(Crypt::decryptString($row->webhook_secret))->toBe('plain-secret')
        ->and($site->fresh()->deployTriggerUrl())->toEndWith('/plain-token');
});

test('existing deploy tokens and webhook secrets are encrypted in place, and can be decrypted again', function () {
    $migration = require database_path('migrations/2026_10_18_234545_encrypt_site_deploy_secrets.php');
    $site = Site::factory()->create(['deploy_token' => 'plain-token', 'webhook_secret' => 'plain-secret']);

    $migration->down();

    expect(DB::table('sites')->where('id', $site->id)->first())
        ->deploy_token->toBe('plain-token')
        ->webhook_secret->toBe('plain-secret');

    $migration->up();

    expect($site->fresh())
        ->deploy_token->toBe('plain-token')
        ->webhook_secret->toBe('plain-secret');
});

test('deployments run on the deployment queue', function () {
    Queue::fake();
    config(['swage.deployment_queue' => 'deployments']);
    $site = Site::factory()->withRepository()->create();

    $this->post($site->deployTriggerUrl())->assertAccepted();

    Queue::assertPushedOn('deployments', DeploySite::class);
});

test('a zero downtime deployment removes its release when it fails before going live', function () {
    $zeroDowntime = DeployScript::render(Site::factory()->withRepository()->create());
    $inPlace = DeployScript::render(Site::factory()->withRepository()->create(['zero_downtime' => false]));

    expect($zeroDowntime)
        ->toContain('trap swage_discard_failed_release EXIT')
        ->toContain('rm -rf "$SWAGE_RELEASE_DIRECTORY"')
        ->and(strpos($zeroDowntime, 'export SWAGE_RELEASE_ACTIVATED=1'))->toBeGreaterThan(strpos($zeroDowntime, 'mv -Tf "$SWAGE_SITE_PATH/current-next" "$SWAGE_SITE_PATH/current"'))
        ->and($inPlace)->not->toContain('swage_discard_failed_release');
});

test('deployments link their commit to the provider\'s page for it', function () {
    $provider = new class('hub') extends FakeSourceControlProvider implements LinksToCommits
    {
        public function commitUrl(string $repository, string $commit): string
        {
            return "https://hub.example.com/{$repository}/-/commit/{$commit}";
        }
    };
    app(SourceControlProviders::class)->register($provider);
    $site = Site::factory()->withRepository()->create(['source_control' => 'hub', 'repository' => 'acme/app']);
    Deployment::factory()->for($site)->create(['commit_hash' => 'abc1234def5678']);

    $this->actingAs($site->server->user)->get(route('servers.sites.show', [$site->server, $site]))
        ->assertOk()
        ->assertSee('href="https://hub.example.com/acme/app/-/commit/abc1234def5678"', false);
});

test('a commit links to a page built from the clone url when the provider has no commit links', function (string $cloneUrl, ?string $url) {
    $site = Site::factory()->withCustomRepository()->create(['clone_url' => $cloneUrl]);

    expect($site->commitUrl('abc1234'))->toBe($url);
})->with([
    'github over ssh' => ['git@github.com:acme/app.git', 'https://github.com/acme/app/commit/abc1234'],
    'ssh url with a port' => ['ssh://git@git.example.com:2222/acme/app.git', 'https://git.example.com/acme/app/commit/abc1234'],
    'unknown form' => ['/srv/git/app.git', null],
]);
