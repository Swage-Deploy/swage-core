<?php

use App\Models\Database;
use App\Models\Server;

test('app pages ask for confirmation with the themed modal', function () {
    $server = Server::factory()->create();

    $this->actingAs($server->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('x-on:swage-confirm.window', escape: false)
        // @js() swallows the line break after it, so each statement in the listener must end with a semicolon.
        ->assertSee("label = \$event.detail.label || 'Confirm';\n", escape: false)
        ->assertSee('data-test="confirm-action-button"', escape: false)
        ->assertSee('data-test="confirm-danger-action-button"', escape: false);
});

test('destructive actions are confirmed as dangerous', function () {
    $server = Server::factory()->create();
    Database::factory()->for($server)->create(['name' => 'shop']);

    $this->actingAs($server->user)
        ->get(route('servers.databases', $server))
        ->assertOk()
        ->assertSee('wire:confirm.danger="Drop the shop database and all of its data?"', escape: false);
});
