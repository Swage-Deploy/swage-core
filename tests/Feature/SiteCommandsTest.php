<?php

use App\Enums\DeploymentStatus;
use App\Enums\MemberRole;
use App\Jobs\RunSiteCommand;
use App\Models\Site;
use App\Models\SiteCommand;
use App\Models\User;
use App\Sites\ArtisanCommand;
use App\Ssh\ScriptResult;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function siteCommands(Site $site, ?User $user = null)
{
    return Livewire::actingAs($user ?? $site->server->user)->test('pages::sites.commands', ['server' => $site->server, 'site' => $site]);
}

test('what follows php artisan is split into arguments the way a shell would', function (string $input, array $arguments) {
    expect(ArtisanCommand::parse($input)->arguments)->toBe($arguments);
})->with([
    'plain' => ['migrate --force', ['migrate', '--force']],
    'extra spaces' => ['  cache:clear   ', ['cache:clear']],
    'typed with php artisan' => ['php artisan about', ['about']],
    'double quotes' => ['app:greet "Taylor Otwell" --loud', ['app:greet', 'Taylor Otwell', '--loud']],
    'single quotes' => ["app:note 'it is \"done\"'", ['app:note', 'it is "done"']],
    'escaped space' => ['app:note a\\ b', ['app:note', 'a b']],
    'option with value' => ['about --only=environment', ['about', '--only=environment']],
]);

test('commands that are not artisan commands, or never finish, are refused', function (string $input, string $error) {
    expect(fn () => ArtisanCommand::parse($input))->toThrow(InvalidArgumentException::class, $error);
})->with([
    'empty' => ['', 'Enter a command'],
    'a shell command' => ['migrate; rm -rf /', 'Start with the name of an Artisan command'],
    'a substitution' => ['$(whoami)', 'Start with the name of an Artisan command'],
    'two lines' => ["migrate\nwhoami", 'one line'],
    'an unclosed quote' => ['app:greet "Taylor', 'A quote is not closed'],
    'tinker' => ['tinker', 'tinker keeps running'],
    'queue work' => ['queue:work --tries=3', 'queue:work keeps running'],
]);

test('every argument is quoted, so shell syntax reaches artisan as plain text', function () {
    $site = Site::factory()->isolated('example')->create(['domain' => 'example.com']);

    $script = ArtisanCommand::parse('app:report "$(id)" && whoami ; `reboot`')->script($site);

    expect($script)
        ->toContain("cd '/home/example/example.com/current'")
        ->toContain("exec php8.5 artisan 'app:report' '$(id)' '&&' 'whoami' ';' '`reboot`' '--no-interaction'");
});

test('running a command queues it and shows its output', function () {
    Queue::fake();
    $site = Site::factory()->withRepository()->create();

    siteCommands($site)
        ->set('command', 'migrate --force')
        ->call('run')
        ->assertHasNoErrors()
        ->assertSet('command', '')
        ->assertSet('viewingCommandId', SiteCommand::sole()->id);

    expect(SiteCommand::sole())
        ->command->toBe('migrate --force')
        ->status->toBe(DeploymentStatus::Pending)
        ->user_id->toBe($site->server->user_id);

    Queue::assertPushed(RunSiteCommand::class);
});

test('a refused command is shown as an error and not run', function () {
    Queue::fake();
    $site = Site::factory()->withRepository()->create();

    siteCommands($site)->set('command', 'tinker')->call('run')->assertHasErrors(['command']);

    expect(SiteCommand::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('the command runs as the site\'s user and keeps its output and exit code, with secrets masked', function () {
    $shell = fakeShell()->respondTo('artisan', new ScriptResult(1, "Migrating with db-secret-password\nFailed"));
    $site = Site::factory()->isolated('example')->withRepository()->create();
    $site->server->update(['database_password' => 'db-secret-password']);
    $command = SiteCommand::factory()->for($site)->create(['command' => 'migrate --force', 'status' => DeploymentStatus::Pending, 'output' => null, 'exit_code' => null]);

    RunSiteCommand::dispatchSync($command);

    expect($command->fresh())
        ->status->toBe(DeploymentStatus::Failed)
        ->exit_code->toBe(1)
        ->output->toContain('********')
        ->output->not->toContain('db-secret-password');

    expect(collect($shell->ran)->last())
        ->as->toBe('example')
        ->script->toContain("artisan 'migrate' '--force' '--no-interaction'");
});

test('only those who manage the site run commands, and viewers do not see the tab', function () {
    $site = Site::factory()->withRepository()->create();
    $viewer = User::factory()->create();
    $site->server->members()->attach($viewer->id, ['role' => MemberRole::Viewer->value]);

    $this->actingAs($viewer)->get(route('servers.sites.commands', [$site->server, $site]))->assertForbidden();
    $this->get(route('servers.sites.show', [$site->server, $site]))->assertDontSee(route('servers.sites.commands', [$site->server, $site]));

    $this->actingAs($site->server->user)->get(route('servers.sites.commands', [$site->server, $site]))
        ->assertOk()
        ->assertSee('php artisan');
});

test('quoted arguments are stored so they run the same again', function () {
    Queue::fake();
    $site = Site::factory()->withRepository()->create();

    siteCommands($site)->set('command', 'app:greet "Taylor Otwell" --loud')->call('run');

    $stored = SiteCommand::sole()->command;

    expect($stored)->toBe("app:greet 'Taylor Otwell' --loud")
        ->and(ArtisanCommand::parse($stored)->arguments)->toBe(['app:greet', 'Taylor Otwell', '--loud']);
});
