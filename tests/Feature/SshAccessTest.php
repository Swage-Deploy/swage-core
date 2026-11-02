<?php

use App\Actions\Servers\RemoveSshKey;
use App\Enums\MemberRole;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Servers\AuthorizedKey;
use App\Ssh\Script;
use App\Ssh\ScriptResult;
use Illuminate\Support\Str;
use Livewire\Livewire;

const SSH_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIH19p8RfJ0qVLhfLDRMCKadYQMsQ3/jBzurYkYZTJ3jA';
const PANEL_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJyNbYGPIb0SBt0zoUlg2hcA8pulBF7MeTdeTsf8wb1E';

beforeEach(function () {
    Str::createRandomStringsUsing(fn () => 'marker');
});

afterEach(function () {
    Str::createRandomStringsNormally();
});

/**
 * Answer the page's read of the server with these authorized_keys files and denied users.
 *
 * @param  array<string, string>  $keys
 * @param  list<string>  $denied
 * @param  list<string>  $deniedBySwage
 */
function sshServerHolds(array $keys, array $denied = [], ?array $deniedBySwage = null): string
{
    $output = "@@marker denied\n".implode("\n", $denied)."\n@@marker swage-denied\n".implode("\n", $deniedBySwage ?? $denied)."\n";

    foreach ($keys as $user => $file) {
        $output .= "@@marker keys {$user}\n{$file}\n";
    }

    return $output;
}

function sshServer(): Server
{
    $server = Server::factory()->create(['ssh_public_key' => PANEL_KEY.' swage']);
    Site::factory()->for($server)->systemUser('shop')->create();

    return $server;
}

test('the page shows the keys and access the server reports, with swage\'s own key locked', function () {
    $server = sshServer();
    fakeShell()->respondTo('@@marker keys', new ScriptResult(0, sshServerHolds([
        'root' => PANEL_KEY." swage\n# a comment\nnot a key",
        'shop' => 'from="10.0.0.1" '.SSH_KEY.' jo@laptop',
    ], denied: ['shop'])));

    Livewire::actingAs($server->user)->test('pages::servers.ssh', ['server' => $server])
        ->call('load')
        ->assertSee('Swage manages the server with this key')
        ->assertSee('jo@laptop')
        ->assertSee('Restricted')
        ->assertSee('SHA256:faX7jwkevMEaUrvWYxryw3yGf0V05qMksVLKqZFs71c')
        ->assertSee('This user cannot sign in over SSH')
        ->assertDontSee("remove('root', 0)", escape: false)
        ->assertSee("remove('shop', 0)", escape: false)
        ->assertDontSee('<option value="'.config('swage.user').'"', escape: false);

    expect($server->sshUsers())->toBe(['root', 'shop']);
});

test('site users\' keys are read as those users', function () {
    $script = Script::render('ssh.read-access', ['marker' => '@@marker', 'sshUsers' => ['root', 'shop']]);

    expect($script)
        ->toContain('cat /root/.ssh/authorized_keys')
        ->toContain("sudo -n -u 'shop' cat '/home/shop/.ssh/authorized_keys'")
        ->toContain('sshd -T');
});

test('a key can be added to a site user, named after its comment, and the page shows it after', function () {
    $server = sshServer();
    $shell = fakeShell()->respondTo('@@marker keys', new ScriptResult(0, sshServerHolds(['root' => PANEL_KEY, 'shop' => ''])));

    Livewire::actingAs($server->user)->test('pages::servers.ssh', ['server' => $server])
        ->call('load')
        ->set('user', 'shop')
        ->set('public_key', '  '.SSH_KEY." jo@laptop\n")
        ->call('add')
        ->assertHasNoErrors()
        ->assertSet('public_key', '');

    $shell->assertRan("sudo -n -u 'shop' bash -s -- '/home/shop/.ssh' 'AAAAC3NzaC1lZDI1NTE5AAAAIH19p8RfJ0qVLhfLDRMCKadYQMsQ3/jBzurYkYZTJ3jA' '".SSH_KEY." jo@laptop'");
    expect($shell->lastScript())->toContain('@@marker keys');
});

test('keys must be valid, new, and not swage\'s own', function (string $publicKey) {
    $server = sshServer();
    $shell = fakeShell()->respondTo('@@marker keys', new ScriptResult(0, sshServerHolds(['root' => PANEL_KEY.' swage', 'shop' => SSH_KEY.' jo@laptop'])));

    Livewire::actingAs($server->user)->test('pages::servers.ssh', ['server' => $server])
        ->call('load')
        ->set('user', 'shop')
        ->set('name', 'laptop')
        ->set('public_key', $publicKey)
        ->call('add')
        ->assertHasErrors(['public_key']);

    expect(collect($shell->ran)->pluck('script')->filter(fn ($script) => str_contains($script, 'bash -s')))->toBeEmpty();
})->with([
    'not a key' => ['hello world'],
    'wrong type' => ['ssh-rsa AAAAC3NzaC1lZDI1NTE5AAAAIH19p8RfJ0qVLhfLDRMCKadYQMsQ3/jBzurYkYZTJ3jA'],
    'with options' => ['command="ls" '.SSH_KEY],
    'two lines' => [SSH_KEY."\n".SSH_KEY],
    'already on the server' => [SSH_KEY.' other comment'],
    'swage\'s key' => [PANEL_KEY],
]);

test('names that could break the authorized_keys line are rejected', function () {
    $server = sshServer();
    fakeShell()->respondTo('@@marker keys', new ScriptResult(0, sshServerHolds(['root' => '', 'shop' => ''])));

    Livewire::actingAs($server->user)->test('pages::servers.ssh', ['server' => $server])
        ->call('load')
        ->set('user', 'shop')->set('name', "laptop'; rm -rf /")->set('public_key', SSH_KEY)
        ->call('add')
        ->assertHasErrors(['name']);
});

test('swage\'s own key can never be removed', function () {
    $server = sshServer();
    $shell = fakeShell()->respondTo('@@marker keys', new ScriptResult(0, sshServerHolds(['root' => PANEL_KEY." swage\n".SSH_KEY.' jo', 'shop' => ''])));

    Livewire::actingAs($server->user)->test('pages::servers.ssh', ['server' => $server])
        ->call('load')
        ->call('remove', 'root', 0)
        ->assertDispatched('toast-show');

    expect(collect($shell->ran)->contains(fn ($run) => str_contains($run['script'], 'bash -s')))->toBeFalse()
        ->and(fn () => app(RemoveSshKey::class)->handle($server, 'root', AuthorizedKey::parse(PANEL_KEY)))->toThrow(InvalidArgumentException::class);
});

test('removing a key edits the user\'s authorized_keys', function () {
    $server = sshServer();
    $shell = fakeShell()->respondTo('@@marker keys', new ScriptResult(0, sshServerHolds(['root' => PANEL_KEY." swage\n".SSH_KEY.' jo', 'shop' => ''])));

    Livewire::actingAs($server->user)->test('pages::servers.ssh', ['server' => $server])
        ->call('load')
        ->call('remove', 'root', 1)
        ->assertDispatched('toast-show');

    $shell->assertRan("bash -s -- /root/.ssh 'AAAAC3NzaC1lZDI1NTE5AAAAIH19p8RfJ0qVLhfLDRMCKadYQMsQ3/jBzurYkYZTJ3jA' <<'SWAGE_EOF'");
    $shell->assertRan('if ($i == data) next');
});

test('turning off a site user\'s ssh access denies it in sshd and ends its sessions', function () {
    $server = sshServer();
    $shell = fakeShell()->respondTo('@@marker keys', new ScriptResult(0, sshServerHolds(['root' => '', 'shop' => ''])));

    Livewire::actingAs($server->user)->test('pages::servers.ssh', ['server' => $server])
        ->call('load')
        ->call('toggle', 'shop');

    $shell->assertRan("printf '%s\\n' \$SSH_DENIED_BEFORE 'shop'");
    $shell->assertRan('sshd -t');
    $shell->assertRan("pkill -KILL -u 'shop' sshd");
});

test('turning ssh access back on takes the user off swage\'s deny list', function () {
    $server = sshServer();
    $shell = fakeShell()->respondTo('@@marker keys', new ScriptResult(0, sshServerHolds(['root' => '', 'shop' => ''], denied: ['shop'])));

    Livewire::actingAs($server->user)->test('pages::servers.ssh', ['server' => $server])
        ->call('load')
        ->call('toggle', 'shop');

    $shell->assertRan("grep -vx -e '' -e 'shop'");
    expect(collect($shell->ran)->contains(fn ($run) => str_contains($run['script'], 'pkill')))->toBeFalse();
});

test('users denied by the server\'s own sshd config can\'t be turned on from swage', function () {
    $server = sshServer();
    $shell = fakeShell()->respondTo('@@marker keys', new ScriptResult(0, sshServerHolds(['root' => '', 'shop' => ''], denied: ['shop'], deniedBySwage: [])));

    Livewire::actingAs($server->user)->test('pages::servers.ssh', ['server' => $server])
        ->call('load')
        ->assertSee('so Swage can\'t turn it back on')
        ->call('toggle', 'shop');

    expect(collect($shell->ran)->contains(fn ($run) => str_contains($run['script'], 'SSH_DENIED')))->toBeFalse();
});

test('an unreachable server shows why the keys could not be read', function () {
    $server = sshServer();
    fakeShell()->respondTo('@@marker keys', new ScriptResult(255, 'Connection refused'));

    Livewire::actingAs($server->user)->test('pages::servers.ssh', ['server' => $server])
        ->call('load')
        ->assertSee('Unable to read SSH access from the server')
        ->assertSee('Connection refused');
});

test('only the server\'s owner can manage root\'s keys, and viewers cannot change anything', function () {
    $server = sshServer();
    $shell = fakeShell()->respondTo('@@marker keys', new ScriptResult(0, sshServerHolds(['root' => SSH_KEY.' jo', 'shop' => ''])));
    $manager = User::factory()->create();
    $viewer = User::factory()->create();
    $server->members()->attach([$manager->id => ['role' => MemberRole::Manager->value], $viewer->id => ['role' => MemberRole::Viewer->value]]);

    Livewire::actingAs($manager)->test('pages::servers.ssh', ['server' => $server])
        ->call('load')
        ->assertDontSee('<option value="root"', escape: false)
        ->set('user', 'root')->set('public_key', SSH_KEY)->call('add')
        ->call('remove', 'root', 0);

    Livewire::actingAs($viewer)->test('pages::servers.ssh', ['server' => $server])
        ->call('load')
        ->assertDontSee('Add an SSH key')
        ->call('toggle', 'shop');

    expect(collect($shell->ran)->pluck('script')->filter(fn ($script) => ! str_contains($script, '@@marker keys')))->toBeEmpty();
});

test('removing a site takes its user off swage\'s deny list', function () {
    $site = Site::factory()->systemUser('shop')->create();

    expect(Script::render('sites.remove', ['site' => $site]))->toContain("grep -vx -e '' -e 'shop'");
});

test('authorized_keys lines are parsed with their options and comments', function () {
    $key = AuthorizedKey::parse('no-pty,from="10.0.0.1 10.0.0.2" '.SSH_KEY.' jo@laptop work');

    expect($key)
        ->options->toBe('no-pty,from="10.0.0.1 10.0.0.2"')
        ->type->toBe('ssh-ed25519')
        ->comment->toBe('jo@laptop work')
        ->and($key->fingerprint())->toBe('SHA256:faX7jwkevMEaUrvWYxryw3yGf0V05qMksVLKqZFs71c')
        ->and(AuthorizedKey::parse('# '.SSH_KEY))->toBeNull();
});
