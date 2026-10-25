<?php

use App\Enums\ApiAbility;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('API keys')] class extends Component {
    /**
     * How long a new key lasts, in days, offered in the expiry picker.
     *
     * @var list<int>
     */
    public const array EXPIRY_DAYS = [30, 90, 365];

    public string $name = '';

    /**
     * Days until the new key expires, or "never".
     */
    public string $expires = '90';

    /**
     * What the new key may do in each area: "none", "read" or "write".
     *
     * @var array<string, string>
     */
    public array $access = [];

    /**
     * A key just created, shown once and then forgotten.
     */
    #[Locked]
    public ?string $plainTextKey = null;

    public function mount(): void
    {
        $this->resetAccess();
    }

    /**
     * @return Collection<int, PersonalAccessToken>
     */
    #[Computed]
    public function keys(): Collection
    {
        return Auth::user()->tokens()->latest('id')->get();
    }

    public function add(): void
    {
        $this->reset('name', 'expires');
        $this->resetAccess();
        $this->resetValidation();

        Flux::modal('api-key-form')->show();
    }

    /**
     * Give every area read access, so the form starts from what a reporting script would need.
     */
    public function readOnly(): void
    {
        $this->access = array_map(fn (): string => 'read', ApiAbility::areas());
    }

    public function fullAccess(): void
    {
        $this->access = array_map(fn (): string => 'write', ApiAbility::areas());
    }

    public function create(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'expires' => ['required', Rule::in([...array_map('strval', self::EXPIRY_DAYS), 'never'])],
            'access' => ['required', 'array'],
            'access.*' => ['required', Rule::in(['none', 'read', 'write'])],
        ]);

        $abilities = collect($validated['access'])
            ->only(array_keys(ApiAbility::areas()))
            ->reject(fn (string $level): bool => $level === 'none')
            ->map(fn (string $level, string $area): string => "{$area}:{$level}")
            ->values()
            ->all();

        if ($abilities === []) {
            $this->addError('access', __('Give the key access to at least one area.'));

            return;
        }

        $token = Auth::user()->createToken(
            $validated['name'],
            ApiAbility::normalize($abilities),
            $validated['expires'] === 'never' ? null : now()->addDays((int) $validated['expires']),
        );

        unset($this->keys);

        $this->plainTextKey = $token->plainTextToken;

        Flux::modal('api-key-form')->close();
        Flux::modal('new-api-key')->show();
    }

    /**
     * Stop a key working straight away.
     */
    public function revoke(int $keyId): void
    {
        $key = Auth::user()->tokens()->findOrFail($keyId);
        $key->delete();

        unset($this->keys);

        Flux::toast(variant: 'success', text: __(':name revoked.', ['name' => $key->name]));
    }

    public function forgetKey(): void
    {
        $this->plainTextKey = null;
    }

    /**
     * Describe what a key may do in each area it reaches, such as "Servers: read".
     *
     * @return list<string>
     */
    public function describe(PersonalAccessToken $key): array
    {
        if (empty(array_diff(ApiAbility::all(), $key->abilities ?? []))) {
            return [__('Full access')];
        }

        return collect(ApiAbility::areas())
            ->map(fn (array $area, string $id): ?string => match (true) {
                in_array("{$id}:write", $key->abilities ?? [], true) => __(':area: read and write', ['area' => $area['label']]),
                in_array("{$id}:read", $key->abilities ?? [], true) => __(':area: read', ['area' => $area['label']]),
                default => null,
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function resetAccess(): void
    {
        $this->access = array_map(fn (): string => 'none', ApiAbility::areas());
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('API keys') }}</flux:heading>

    <x-pages::settings.layout :heading="__('API keys')" :subheading="__('Keys for scripts and CI to use the Swage API. A key acts as you, reaching only the servers and sites you can, and only in the areas you give it.')">
        <div class="flex flex-col gap-4">
            <div>
                <flux:button variant="primary" icon="key" wire:click="add">{{ __('New API key') }}</flux:button>
            </div>

            @forelse ($this->keys as $key)
                <div wire:key="api-key-{{ $key->id }}" class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-4 dark:border-white/10">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <flux:heading class="flex items-center gap-2">
                                {{ $key->name }}
                                @if ($key->expires_at?->isPast())
                                    <flux:badge size="sm" color="red">{{ __('Expired') }}</flux:badge>
                                @endif
                            </flux:heading>
                            <flux:text class="mt-1">
                                {{ $key->last_used_at ? __('Last used :time', ['time' => $key->last_used_at->diffForHumans()]) : __('Never used') }}
                                ·
                                {{ $key->expires_at ? ($key->expires_at->isPast() ? __('Expired :time', ['time' => $key->expires_at->diffForHumans()]) : __('Expires :time', ['time' => $key->expires_at->diffForHumans()])) : __('Never expires') }}
                            </flux:text>
                        </div>

                        <flux:button size="sm" variant="danger" wire:click="revoke({{ $key->id }})"
                            wire:confirm.danger="{{ __('Revoke :name? Anything using it stops working straight away.', ['name' => $key->name]) }}">{{ __('Revoke') }}</flux:button>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($this->describe($key) as $description)
                            <flux:badge size="sm">{{ $description }}</flux:badge>
                        @endforeach
                    </div>
                </div>
            @empty
                <flux:text>{{ __('No API keys yet.') }}</flux:text>
            @endforelse
        </div>
    </x-pages::settings.layout>

    <flux:modal name="api-key-form" class="md:w-[34rem]">
        <form wire:submit="create" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('New API key') }}</flux:heading>
                <flux:text class="mt-2">{{ __('The key is shown once, so have somewhere to store it, such as your CI\'s secrets.') }}</flux:text>
            </div>

            <flux:input wire:model="name" :label="__('Name')" :placeholder="__('Deploys from CI')" required />

            <flux:select wire:model="expires" :label="__('Expires')">
                @foreach ($this::EXPIRY_DAYS as $days)
                    <flux:select.option :value="(string) $days">{{ __('In :days days', ['days' => $days]) }}</flux:select.option>
                @endforeach
                <flux:select.option value="never">{{ __('Never') }}</flux:select.option>
            </flux:select>

            <flux:fieldset>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:legend class="!mb-0">{{ __('Access') }}</flux:legend>
                    <div class="flex gap-1">
                        <flux:button size="xs" variant="ghost" wire:click="readOnly">{{ __('Read everything') }}</flux:button>
                        <flux:button size="xs" variant="ghost" wire:click="fullAccess">{{ __('Full access') }}</flux:button>
                    </div>
                </div>

                <div class="mt-3 flex flex-col divide-y divide-zinc-200 dark:divide-white/10">
                    @foreach (App\Enums\ApiAbility::areas() as $id => $area)
                        <div wire:key="access-{{ $id }}" class="flex flex-wrap items-center justify-between gap-3 py-2.5">
                            <div class="min-w-0 flex-1">
                                <flux:heading size="sm">{{ $area['label'] }}</flux:heading>
                                <flux:text size="sm">{{ ($access[$id] ?? 'none') === 'write' ? $area['write'] : $area['read'] }}</flux:text>
                            </div>
                            <flux:radio.group wire:model.live="access.{{ $id }}" variant="segmented" size="sm">
                                <flux:radio value="none" :label="__('None')" />
                                <flux:radio value="read" :label="__('Read')" />
                                <flux:radio value="write" :label="__('Write')" />
                            </flux:radio.group>
                        </div>
                    @endforeach
                </div>

                <flux:error name="access" />
            </flux:fieldset>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Create key') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="new-api-key" class="md:w-[34rem]" wire:close="forgetKey">
        <div class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('Your new API key') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Copy it now. It is not shown again. Send it in the Authorization header as a bearer token.') }}</flux:text>
            </div>

            <flux:input readonly copyable :value="$plainTextKey" class="font-mono" />

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button variant="primary">{{ __('Done') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</section>
