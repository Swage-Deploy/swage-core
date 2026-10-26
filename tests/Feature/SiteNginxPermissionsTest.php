<?php

use App\Enums\MemberRole;
use App\Enums\ResourceStatus;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

test('a manager of only the site cannot change its custom nginx', function () {
    $shell = fakeShell();
    $site = Site::factory()->create(['status' => ResourceStatus::Installed]);
    $manager = User::factory()->create();
    $site->members()->attach($manager->id, ['role' => MemberRole::Manager->value]);

    Livewire::actingAs($manager)
        ->test('pages::sites.nginx', ['server' => $site->server, 'site' => $site])
        ->assertSee('Only the server\'s owner and Managers can change custom nginx')
        ->assertDontSee('Save & apply')
        ->set('override', 'access_log /etc/profile.d/swage.sh;')
        ->call('save');

    $shell->assertNothingRan();
    expect($site->fresh()->nginx_override)->toBeNull();
});

test('a manager of the server can change a site\'s custom nginx', function () {
    $shell = fakeShell();
    $site = Site::factory()->create(['status' => ResourceStatus::Installed]);
    $manager = User::factory()->create();
    $site->server->members()->attach($manager->id, ['role' => MemberRole::Manager->value]);

    Livewire::actingAs($manager)
        ->test('pages::sites.nginx', ['server' => $site->server, 'site' => $site])
        ->assertSee('Save & apply')
        ->set('override', 'client_max_body_size 1g;')
        ->call('save')
        ->assertSet('failureOutput', null);

    expect($shell->lastScript())->toContain("client_max_body_size 1g;\n")
        ->and($site->fresh()->nginx_override)->toBe('client_max_body_size 1g;');
});
