<?php

use App\Models\Server;
use App\Ssh\Script;
use App\Ssh\ScriptResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

test('moving checks every active server and swaps the old host for the new one in fail2ban', function () {
    $shell = fakeShell()->respondTo('ignoreip', new ScriptResult(0, "Reachable from 203.0.113.9.\n"));
    $active = Server::factory()->create(['name' => 'web']);
    Server::factory()->pending()->create();

    $this->artisan('swage:host-moved', ['--from' => ['198.51.100.7 10.0.0.4', 'not-an-address; rm -rf /']])
        ->expectsOutputToContain('Reachable from 203.0.113.9.')
        ->expectsOutputToContain('All 1 server(s) can be reached from this host.')
        ->assertSuccessful();

    expect($shell->ran)->toHaveCount(1)
        ->and($shell->ran[0]['server']->is($active))->toBeTrue()
        ->and($shell->ran[0]['script'])->toContain("old='198.51.100.7 10.0.0.4'")
        ->not->toContain('rm -rf');
});

test('moving fails and names the servers that could not be reached', function () {
    fakeShell()->respondTo('ignoreip', new ScriptResult(255, 'Connection timed out'));
    Server::factory()->create(['name' => 'db']);

    $this->artisan('swage:host-moved', ['--from' => ['198.51.100.7']])
        ->expectsOutputToContain('Connection timed out')
        ->expectsOutputToContain('1 server(s) could not be updated: db.')
        ->assertFailed();
});

test('the server script swaps the old address for the new one in every jail that has an ignoreip', function () {
    if (! Process::run('command -v bash')->successful()) {
        $this->markTestSkipped('bash is not available to run the script.');
    }

    $jails = sys_get_temp_dir().'/swage-jails-'.uniqid();
    File::ensureDirectoryExists($jails);
    File::put("{$jails}/swage.conf", "[DEFAULT]\nignoreip = 127.0.0.1/8 ::1 198.51.100.7\nbantime = 1h\n");
    File::put("{$jails}/probes.conf", "[probes]\nignoreip = 127.0.0.1/8 ::1 198.51.100.7 173.245.48.0/20\n");
    File::put("{$jails}/current.conf", "[current]\nignoreip = 127.0.0.1/8 203.0.113.9\n");
    File::put("{$jails}/other.conf", "[other]\nenabled = true\n");

    $script = str_replace('/etc/fail2ban/jail.d', $jails, Script::render('host-moved', ['old' => ['198.51.100.7']]));
    $result = Process::env(['SSH_CLIENT' => '203.0.113.9 51234 22'])->run(['bash', '-c', $script]);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('Reachable from 203.0.113.9.')
        ->and(File::get("{$jails}/swage.conf"))->toBe("[DEFAULT]\nignoreip = 127.0.0.1/8 ::1 203.0.113.9\nbantime = 1h\n")
        ->and(File::get("{$jails}/probes.conf"))->toBe("[probes]\nignoreip = 127.0.0.1/8 ::1 173.245.48.0/20 203.0.113.9\n")
        ->and(File::get("{$jails}/current.conf"))->toBe("[current]\nignoreip = 127.0.0.1/8 203.0.113.9\n")
        ->and(File::get("{$jails}/other.conf"))->toBe("[other]\nenabled = true\n")
        ->and(File::glob("{$jails}/*.swage-moved"))->toBeEmpty();

    File::deleteDirectory($jails);
});

test('the move script refuses bad options before touching anything', function (array $arguments, string $error) {
    if (! Process::run('command -v bash')->successful()) {
        $this->markTestSkipped('bash is not available to run the script.');
    }

    $result = Process::run(['bash', resource_path('scripts/move-swage.sh'), ...$arguments]);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain($error);
})->with([
    'missing host' => [['--from'], '--from needs a value'],
    'unknown option' => [['--to=new'], 'Unknown option: --to=new'],
]);

test('the move script checks the old host key, stops the old panel restorably and removes its key afterwards', function () {
    $script = File::get(resource_path('scripts/move-swage.sh'));

    expect($script)
        ->toContain('StrictHostKeyChecking=yes')
        ->toContain('ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub')
        ->toContain('permitrootlogin must be prohibit-password')
        ->toContain('/root/swage-moved/restore.sh')
        ->toContain('artisan down')
        ->toContain('mysqldump --single-transaction')
        ->toContain('artisan swage:host-moved')
        ->toContain("grep -vxF '\$PUBLIC_KEY' /root/.ssh/authorized_keys");

    // Every server is checked from the new host, and the move confirmed, before anything on the old host changes.
    expect(strpos($script, 'check_servers'))->toBeLessThan(strpos($script, 'Type move to continue'))
        ->and(strpos($script, 'Type move to continue'))->toBeLessThan(strpos($script, 'artisan down'))
        ->and($script)->toContain('-o HostKeyAlgorithms=ssh-ed25519')
        ->toContain("trap 'rm -rf /root/swage-move-checks.*' EXIT");
});
