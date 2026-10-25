<?php

use App\Enums\MemberRole;
use App\Enums\ResourceStatus;
use App\Enums\TaskStatus;
use App\Jobs\RestoreDatabase;
use App\Models\Database;
use App\Models\Server;
use App\Models\User;
use App\Ssh\Script;
use App\Ssh\ScriptResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function addTransferMember(Server $server, User $user, MemberRole $role): User
{
    $server->members()->attach($user->id, ['role' => $role->value]);

    return $user;
}

test('a database is exported as a gzipped dump streamed from the server', function () {
    $shell = fakeShell()->streamTo('mysqldump', 'gzipped-dump');
    $database = Database::factory()->create(['name' => 'shop']);

    $response = $this->actingAs($database->server->user)->get(route('servers.databases.export', [$database->server, $database]));

    $response->assertOk()
        ->assertDownload()
        ->assertHeader('Content-Type', 'application/gzip');

    expect($response->streamedContent())->toBe('gzipped-dump')
        ->and($response->headers->get('Content-Disposition'))->toMatch('/shop-\d{4}-\d{2}-\d{2}-\d{6}\.sql\.gz/')
        ->and($shell->lastScript())->toContain('--single-transaction --quick')->toContain("'shop' | gzip -c")
        ->and($database->server->tasks()->sole())
        ->name->toBe('Exporting database shop')
        ->status->toBe(TaskStatus::Finished);
});

test('a failed export is marked failed in the activity log with what the server reported', function () {
    fakeShell()->respondTo('mysqldump', new ScriptResult(exitCode: 2, output: 'mysqldump: Got error: 1049: Unknown database'));
    $database = Database::factory()->create(['name' => 'shop']);

    $this->actingAs($database->server->user)->get(route('servers.databases.export', [$database->server, $database]))->streamedContent();

    expect($database->server->tasks()->sole())
        ->status->toBe(TaskStatus::Failed)
        ->output->toContain('Unknown database')->toContain('incomplete');
});

test('owners, managers and administrators can export a database, but viewers and others cannot', function (Closure $actor, int $status) {
    fakeShell();
    $database = Database::factory()->create();

    $this->actingAs($actor($database->server))
        ->get(route('servers.databases.export', [$database->server, $database]))
        ->assertStatus($status);
})->with([
    'owner' => [fn (Server $server) => $server->user, 200],
    'manager' => [fn (Server $server) => addTransferMember($server, User::factory()->create(), MemberRole::Manager), 200],
    'administrator' => [fn (Server $server) => User::factory()->create(['is_admin' => true]), 200],
    'viewer' => [fn (Server $server) => addTransferMember($server, User::factory()->create(), MemberRole::Viewer), 403],
    'stranger' => [fn (Server $server) => User::factory()->create(), 403],
]);

test('postgres, system and busy databases cannot be exported', function (array $attributes) {
    $shell = fakeShell();
    $database = Database::factory()->create($attributes);

    $this->actingAs($database->server->user)
        ->get(route('servers.databases.export', [$database->server, $database]))
        ->assertNotFound();

    $shell->assertNothingRan();
})->with([
    'postgres' => [['engine' => 'postgres']],
    'system' => [['name' => 'mysql']],
    'busy' => [['status' => ResourceStatus::Restoring]],
]);

test('a database on another server cannot be exported through this one', function () {
    $server = Server::factory()->create();
    $database = Database::factory()->create();

    $this->actingAs($server->user)
        ->get(route('servers.databases.export', [$server, $database]))
        ->assertNotFound();
});

test('a database is restored from an uploaded dump after its name is typed', function () {
    Storage::fake('local');
    $shell = fakeShell();
    $database = Database::factory()->create(['name' => 'shop']);

    Livewire::actingAs($database->server->user)->test('pages::servers.databases', ['server' => $database->server])
        ->call('startRestore', $database->id)
        ->set('restoreFile', UploadedFile::fake()->createWithContent('shop.sql', 'CREATE TABLE orders (id int);'))
        ->set('restoreConfirmation', 'shop')
        ->call('restoreDatabase')
        ->assertHasNoErrors();

    expect($shell->uploaded)->toHaveCount(1)
        ->and($shell->uploaded[0]['contents'])->toBe('CREATE TABLE orders (id int);')
        ->and($shell->uploaded[0]['remote'])->toStartWith('/root/swage-restore-')->toEndWith('.sql');

    expect($shell->lastScript())
        ->toContain($shell->uploaded[0]['remote'])
        ->toContain('DROP DATABASE IF EXISTS `shop`; CREATE DATABASE `shop`')
        ->toContain("cat '{$shell->uploaded[0]['remote']}'")
        ->toContain("| mysql --default-character-set=utf8mb4 'shop'")
        ->not->toContain('gunzip');

    expect($database->fresh()->status)->toBe(ResourceStatus::Installed)
        ->and(Storage::disk('local')->allFiles('database-restores'))->toBeEmpty();
});

test('a gzipped dump is recognised by its contents and unzipped on the server', function () {
    Storage::fake('local');
    $shell = fakeShell();
    $database = Database::factory()->create(['name' => 'shop']);

    Livewire::actingAs($database->server->user)->test('pages::servers.databases', ['server' => $database->server])
        ->call('startRestore', $database->id)
        ->set('restoreFile', UploadedFile::fake()->createWithContent('shop.sql.gz', gzencode('CREATE TABLE orders (id int);')))
        ->set('restoreConfirmation', 'shop')
        ->call('restoreDatabase')
        ->assertHasNoErrors();

    expect($shell->uploaded[0]['remote'])->toEndWith('.sql.gz')
        ->and($shell->lastScript())->toContain("gunzip -c '{$shell->uploaded[0]['remote']}'");
});

test('a restore does not start until the database name is typed correctly', function (string $confirmation) {
    Queue::fake();
    Storage::fake('local');
    $database = Database::factory()->create(['name' => 'shop']);

    Livewire::actingAs($database->server->user)->test('pages::servers.databases', ['server' => $database->server])
        ->call('startRestore', $database->id)
        ->set('restoreFile', UploadedFile::fake()->createWithContent('shop.sql', 'SELECT 1;'))
        ->set('restoreConfirmation', $confirmation)
        ->call('restoreDatabase')
        ->assertHasErrors(['restoreConfirmation']);

    Queue::assertNothingPushed();
    expect($database->fresh()->status)->toBe(ResourceStatus::Installed);
})->with(['', 'Shop', 'blog']);

test('a restore needs a sql or gzipped file', function () {
    Queue::fake();
    Storage::fake('local');
    $database = Database::factory()->create(['name' => 'shop']);

    Livewire::actingAs($database->server->user)->test('pages::servers.databases', ['server' => $database->server])
        ->call('startRestore', $database->id)
        ->set('restoreFile', UploadedFile::fake()->createWithContent('shop.php', '<?php echo 1;'))
        ->set('restoreConfirmation', 'shop')
        ->call('restoreDatabase')
        ->assertHasErrors(['restoreFile']);

    Queue::assertNothingPushed();
});

test('viewers cannot restore a database', function () {
    Queue::fake();
    $database = Database::factory()->create();
    $viewer = addTransferMember($database->server, User::factory()->create(), MemberRole::Viewer);

    Storage::fake('local');

    // Refused actions are answered with a toast rather than a 403.
    Livewire::actingAs($viewer)->test('pages::servers.databases', ['server' => $database->server])
        ->call('startRestore', $database->id)
        ->assertSet('restoringDatabaseId', null)
        ->set('restoringDatabaseId', $database->id)
        ->set('restoreFile', UploadedFile::fake()->createWithContent('dump.sql', 'SELECT 1;'))
        ->set('restoreConfirmation', $database->name)
        ->call('restoreDatabase')
        ->assertDontSeeHtml('arrow-up-tray');

    Queue::assertNothingPushed();
});

test('postgres databases cannot be restored from a file', function () {
    $database = Database::factory()->postgres()->create();

    Livewire::actingAs($database->server->user)->test('pages::servers.databases', ['server' => $database->server])
        ->call('startRestore', $database->id)
        ->assertNotFound();
});

test('the restore script refuses dumps that switch databases and drops definers', function () {
    $database = Database::factory()->create(['name' => 'shop']);

    $script = Script::render('databases.restore', ['database' => $database, 'path' => '/root/swage-restore-x.sql', 'compressed' => false]);

    expect($script)
        ->toContain("grep -iE '^[[:space:]]*(USE|CREATE[[:space:]]+(DATABASE|SCHEMA))[[:space:]]'")
        ->toContain("sed -E 's/DEFINER=`[^`]*`@`[^`]*`//g'")
        ->toContain("trap \"rm -f '/root/swage-restore-x.sql'\" EXIT");
});

test('a failed restore marks the database failed and removes the uploaded file', function () {
    Storage::fake('local');
    fakeShell()->respondTo('DROP DATABASE', new ScriptResult(exitCode: 1, output: 'ERROR 1064'));
    $database = Database::factory()->create(['name' => 'shop']);
    Storage::disk('local')->put('database-restores/dump.sql', 'nonsense');

    // The sync queue, unlike dispatchSync, hands the failure to the job.
    rescue(function () use ($database): void {
        RestoreDatabase::dispatch($database, 'database-restores/dump.sql');
    }, report: false);

    expect($database->fresh()->status)->toBe(ResourceStatus::Failed)
        ->and(Storage::disk('local')->exists('database-restores/dump.sql'))->toBeFalse();
});
