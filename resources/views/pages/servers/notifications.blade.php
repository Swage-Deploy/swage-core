<?php

use App\Enums\WebhookKind;
use App\Models\Server;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\Notifier;
use App\Notifications\TestNotification;
use App\Notifications\WebhookUrl;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Server $server;

    public string $name = '';

    public string $kind = 'slack';

    public string $url = '';

    public function mount(): void
    {
        $this->authorize('view', $this->server);
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Notifications').' · '.$this->server->name);
    }

    #[Computed]
    public function webhooks(): Collection
    {
        return $this->server->webhooks()->orderBy('name')->get();
    }

    #[Computed]
    public function recipients(): Collection
    {
        return app(Notifier::class)->recipients($this->server);
    }

    public function add(WebhookUrl $webhookUrl): void
    {
        $this->authorize('update', $this->server);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:64'],
            'kind' => ['required', Rule::enum(WebhookKind::class)],
            'url' => ['required', 'string', 'max:2048', 'url:https', function (string $attribute, string $value, Closure $fail) use ($webhookUrl): void {
                if ($problem = $webhookUrl->problem($value)) {
                    $fail($problem);
                }
            }],
        ]);

        $this->server->webhooks()->create($validated);

        $this->reset('name', 'url');
        unset($this->webhooks);

        Flux::toast(variant: 'success', text: __('Webhook added. Send it a test to check it works.'));
    }

    public function test(int $webhookId, WebhookChannel $channel): void
    {
        $this->authorize('update', $this->server);

        $error = $channel->send($this->server->webhooks()->findOrFail($webhookId), new TestNotification($this->server));

        unset($this->webhooks);

        $error
            ? Flux::toast(variant: 'danger', text: $error)
            : Flux::toast(variant: 'success', text: __('Sent a test notification.'));
    }

    public function remove(int $webhookId): void
    {
        $this->authorize('update', $this->server);

        $this->server->webhooks()->findOrFail($webhookId)->delete();

        unset($this->webhooks);

        Flux::toast(variant: 'success', text: __('Webhook removed.'));
    }
}; ?>

<x-pages::servers.layout :server="$server">
    <div class="flex flex-col gap-6">
        <x-panel icon="bell" :heading="__('Who is notified')" :description="__('When a deployment fails, a certificate stops renewing, the server needs a reboot or a plugin raises an alert, Swage tells the server\'s owner and Managers, and a site\'s Managers about that site. Each person chooses what also reaches them by email in their settings.')">
            <div class="flex flex-wrap gap-2">
                @foreach ($this->recipients as $recipient)
                    <flux:badge size="sm" icon="user">{{ $recipient->name }}</flux:badge>
                @endforeach
            </div>
        </x-panel>

        <x-panel icon="link" :heading="__('Webhooks')" :description="__('Post this server\'s notifications to Slack, Discord or any HTTPS URL.')" flush>
            @if ($this->webhooks->isEmpty())
                <x-empty-state icon="link" :heading="__('No webhooks')">
                    {{ __('Add one to send this server\'s notifications to a team channel.') }}
                </x-empty-state>
            @else
                <ul class="divide-y divide-zinc-200 dark:divide-white/8">
                    @foreach ($this->webhooks as $webhook)
                        <li wire:key="webhook-{{ $webhook->id }}" class="flex flex-wrap items-center justify-between gap-4 px-5 py-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:heading>{{ $webhook->name }}</flux:heading>
                                    <flux:badge size="sm">{{ $webhook->kind->label() }}</flux:badge>
                                </div>
                                <flux:text class="mt-0.5 font-mono text-xs">{{ $webhook->host() }}</flux:text>
                                @if ($webhook->last_error)
                                    <flux:text class="mt-1 text-xs text-red-600! dark:text-red-400!">{{ __('Last post failed: :error', ['error' => $webhook->last_error]) }}</flux:text>
                                @elseif ($webhook->last_sent_at)
                                    <flux:text class="mt-1 text-xs">{{ __('Last posted :time.', ['time' => $webhook->last_sent_at->diffForHumans()]) }}</flux:text>
                                @endif
                            </div>
                            @can('update', $server)
                                <div class="flex gap-2">
                                    <flux:button size="sm" icon="paper-airplane" wire:click="test({{ $webhook->id }})">{{ __('Test') }}</flux:button>
                                    <flux:button size="sm" variant="danger" icon="trash" wire:click="remove({{ $webhook->id }})"
                                        wire:confirm.danger="{{ __('Remove :name? This server\'s notifications stop posting to it.', ['name' => $webhook->name]) }}">{{ __('Remove') }}</flux:button>
                                </div>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-panel>

        @can('update', $server)
            <x-panel icon="plus" :heading="__('Add a webhook')" :description="__('The URL is kept encrypted and isn\'t shown again, since anyone with it can post to the channel.')">
                <form wire:submit="add" class="flex max-w-2xl flex-col gap-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="name" :label="__('Name')" placeholder="#ops" required />
                        <flux:select wire:model.live="kind" :label="__('Type')">
                            @foreach (App\Enums\WebhookKind::cases() as $webhookKind)
                                <flux:select.option :value="$webhookKind->value">{{ $webhookKind->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <flux:input wire:model="url" type="url" :label="__('URL')" :description:trailing="App\Enums\WebhookKind::tryFrom($kind)?->description()" placeholder="https://hooks.slack.com/services/..." class="font-mono" required />

                    <div>
                        <flux:button type="submit" variant="primary" icon="plus">{{ __('Add webhook') }}</flux:button>
                    </div>
                </form>
            </x-panel>
        @endcan
    </div>
</x-pages::servers.layout>
