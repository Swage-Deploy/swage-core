<?php

use App\SourceControl\HasSettings;
use App\SourceControl\ProviderSettings;
use App\SourceControl\Setting;
use App\SourceControl\SourceControlProvider;
use App\SourceControl\SourceControlProviders;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $provider = '';

    /**
     * The values being edited. Secrets are never sent to the browser, so theirs start blank.
     *
     * @var array<string, string>
     */
    public array $values = [];

    /**
     * Which secrets already have a value, so a blank field keeps it.
     *
     * @var array<string, bool>
     */
    #[Locked]
    public array $secretsSet = [];

    public function mount(string $provider, ProviderSettings $settings): void
    {
        $this->authorize('administer');

        $this->provider = $provider;

        abort_unless($this->sourceControlProvider instanceof HasSettings, 404);

        $this->load($settings);
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Configure :provider', ['provider' => $this->sourceControlProvider->name()]));
    }

    #[Computed]
    public function sourceControlProvider(): SourceControlProvider
    {
        return app(SourceControlProviders::class)->find($this->provider) ?? abort(404);
    }

    /**
     * @return list<Setting>
     */
    #[Computed]
    public function settings(): array
    {
        return $this->sourceControlProvider->settings();
    }

    protected function load(ProviderSettings $settings): void
    {
        $stored = $settings->all($this->provider);

        foreach ($this->settings as $setting) {
            $this->values[$setting->key] = $setting->isSecret() ? '' : ($stored[$setting->key] ?? '');
            $this->secretsSet[$setting->key] = $setting->isSecret() && filled($stored[$setting->key] ?? null);
        }
    }

    public function save(ProviderSettings $settings): void
    {
        $this->authorize('administer');

        $rules = [];

        foreach ($this->settings as $setting) {
            $rules["values.{$setting->key}"] = [
                // A secret that already has a value keeps it when left blank.
                $setting->required && ! ($this->secretsSet[$setting->key] ?? false) ? 'required' : 'nullable',
                'string',
                'max:2000',
                ...($setting->type === Setting::URL ? ['url:http,https'] : []),
            ];
        }

        $this->validate($rules, attributes: collect($this->settings)->mapWithKeys(fn (Setting $setting) => ["values.{$setting->key}" => $setting->label])->all());

        $values = collect($this->settings)
            ->reject(fn (Setting $setting): bool => $setting->isSecret() && blank($this->values[$setting->key] ?? null))
            ->mapWithKeys(fn (Setting $setting): array => [$setting->key => trim((string) ($this->values[$setting->key] ?? '')) ?: null])
            ->all();

        $settings->put($this->provider, $values);

        $this->load($settings);
        unset($this->sourceControlProvider);

        Flux::toast(variant: 'success', text: __(':provider settings saved.', ['provider' => $this->sourceControlProvider->name()]));
    }

    /**
     * Remove a secret's value.
     */
    public function clearSecret(string $key, ProviderSettings $settings): void
    {
        $this->authorize('administer');

        abort_unless(collect($this->settings)->contains(fn (Setting $setting): bool => $setting->key === $key && $setting->isSecret()), 404);

        $settings->put($this->provider, [$key => null]);
        $this->load($settings);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:breadcrumbs class="mb-2">
            <flux:breadcrumbs.item :href="route('plugins')" wire:navigate>{{ __('Plugins') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('plugins', 'system')" wire:navigate>{{ __('System') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $this->sourceControlProvider->name() }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
        <div class="flex flex-wrap items-center gap-3">
            <flux:heading size="xl" level="1" class="tracking-tight">{{ __('Configure :provider', ['provider' => $this->sourceControlProvider->name()]) }}</flux:heading>
            @if ($this->sourceControlProvider->isConfigured())
                <flux:badge size="sm" color="emerald">{{ __('Configured') }}</flux:badge>
            @else
                <flux:badge size="sm" color="amber">{{ __('Not configured') }}</flux:badge>
            @endif
        </div>
        <flux:subheading class="max-w-3xl">{{ $this->sourceControlProvider->setupInstructions() }}</flux:subheading>
    </div>

    <x-panel icon="cog-6-tooth" :heading="__('Settings')" class="max-w-3xl">
        <form wire:submit="save" class="flex flex-col gap-5">
            <flux:input readonly copyable :value="route('source-control.callback', $this->sourceControlProvider->id())" :label="__('Callback URL')" class="font-mono"
                :description="__('Where :provider sends people back to after they authorize Swage.', ['provider' => $this->sourceControlProvider->name()])" />

            @foreach ($this->settings as $setting)
                <div wire:key="setting-{{ $setting->key }}">
                    @if ($setting->isSecret())
                        <flux:input wire:model="values.{{ $setting->key }}" type="password" viewable class="font-mono" :label="$setting->label" :description="$setting->description"
                            :placeholder="($secretsSet[$setting->key] ?? false) ? __('Saved. Leave blank to keep it.') : $setting->placeholder"
                            :badge="$setting->required ? null : __('Optional')" />
                        @if ($secretsSet[$setting->key] ?? false)
                            <flux:button size="xs" variant="ghost" class="mt-1" wire:click="clearSecret('{{ $setting->key }}')" wire:confirm.danger="{{ __('Remove the saved :setting?', ['setting' => $setting->label]) }}">{{ __('Remove saved value') }}</flux:button>
                        @endif
                    @else
                        <flux:input wire:model="values.{{ $setting->key }}" :type="$setting->type === App\SourceControl\Setting::URL ? 'url' : 'text'" class="font-mono"
                            :label="$setting->label" :description="$setting->description" :placeholder="$setting->placeholder" :badge="$setting->required ? null : __('Optional')" />
                    @endif
                </div>
            @endforeach

            <div>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </x-panel>
</div>
