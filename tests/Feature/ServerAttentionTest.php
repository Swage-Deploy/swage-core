<?php

use App\Models\Server;
use App\Plugins\Attention;
use App\Plugins\HasAttention;
use App\Servers\ServerAttention;
use App\Servers\ServerIntegrations;
use Illuminate\Database\Eloquent\Collection;
use Tests\Fakes\FakeAttentionServerIntegration;
use Tests\Fakes\FakeServerIntegration;

function attentionIntegration(array $items): FakeAttentionServerIntegration
{
    return tap(new FakeAttentionServerIntegration($items), fn (FakeAttentionServerIntegration $integration) => app(ServerIntegrations::class)->register($integration));
}

test('servers that need looking at get a badge on the servers page, asking plugins about every server at once', function () {
    $quiet = Server::factory()->create(['name' => 'quiet-1']);
    $busy = Server::factory()->create(['name' => 'busy-1', 'user_id' => $quiet->user_id]);
    $integration = attentionIntegration([$busy->id => new Attention(3, Attention::HIGH, '3 security findings')]);

    $this->actingAs($quiet->user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('title="3 security findings"', false)
        ->assertSee('aria-label="3 security findings"', false);

    expect($integration->asked)->toBe(1)
        ->and(app(ServerAttention::class)->for(new Collection([$quiet, $busy])))->toHaveKey($busy->id)->not->toHaveKey($quiet->id);
});

test('a server\'s Plugins tab and its plugin\'s tab show how much needs looking at', function () {
    $server = Server::factory()->create();
    attentionIntegration([$server->id => new Attention(2, Attention::MEDIUM, '2 security findings')]);

    $this->actingAs($server->user)->get(route('servers.show', $server))
        ->assertOk()
        ->assertSee('title="2 security findings"', false);

    $page = $this->actingAs($server->user)->get(route('servers.plugins', $server))->assertOk();

    // One amber badge on the server's Plugins tab, and one on the plugin's own tab.
    expect(substr_count($page->getContent(), 'bg-amber-400/25 dark:bg-amber-400/40 ms-2">2'))->toBe(2);
});

test('nothing to look at shows no badge, and integrations without attention are left alone', function () {
    $server = Server::factory()->create();
    app(ServerIntegrations::class)->register(new FakeServerIntegration);

    $this->actingAs($server->user)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('exclamation-triangle');

    expect(app(ServerAttention::class)->forServer($server))->toBe([]);
});

test('a plugin that fails while saying what needs looking at doesn\'t break the servers page', function () {
    $server = Server::factory()->create();
    app(ServerIntegrations::class)->register(new class extends FakeServerIntegration implements HasAttention
    {
        public function attention(Collection $servers): array
        {
            throw new RuntimeException('The plugin broke.');
        }
    });

    $this->actingAs($server->user)->get(route('dashboard'))->assertOk();
    $this->actingAs($server->user)->get(route('servers.plugins', $server))->assertOk();
});

test('several plugins combine into one badge at the most severe of their severities', function () {
    $combined = ServerAttention::combine([
        'a' => new Attention(2, Attention::LOW, '2 notices'),
        'b' => new Attention(1, Attention::HIGH, '1 alert'),
    ]);

    expect($combined->count)->toBe(3)
        ->and($combined->severity)->toBe(Attention::HIGH)
        ->and($combined->color())->toBe('red')
        ->and($combined->label)->toBe('2 notices, 1 alert')
        ->and(ServerAttention::combine([]))->toBeNull()
        ->and(new Attention(1, Attention::LOW, 'x'))->color()->toBe('zinc');

    expect(fn () => new Attention(1, 'critical', 'x'))->toThrow(InvalidArgumentException::class);
});
