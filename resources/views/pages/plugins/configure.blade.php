<?php

use App\Plugins\HasPluginSettings;
use App\Plugins\HasPluginTab;
use App\Plugins\PluginCategory;
use App\Plugins\PluginSettings;
use App\Servers\ServerIntegration;
use App\Servers\ServerIntegrations;
use App\Sites\SiteIntegration;
use App\Sites\SiteIntegrations;
use App\SourceControl\Setting;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $category = '';

    #[Locked]
    public string $integration = '';

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

    public function mount(string $category, string $integration, PluginSettings $settings): void
    {
        $this->authorize('administer');

        $this->category = $category;
        $this->integration = $integration;

        abort_unless($this->configurable instanceof HasPluginSettings, 404);

        $this->load($settings);
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Configure :plugin', ['plugin' => $this->label]));
    }

    #[Computed]
    public function configurable(): ServerIntegration|SiteIntegration
    {
        $integrations = $this->category === PluginCategory::Server->value ? app(ServerIntegrations::class)->all() : app(SiteIntegrations::class)->all();

        return $integrations[$this->integration] ?? abort(404);
    }

    #[Computed]
    public function label(): string
    {
        return $this->configurable instanceof HasPluginTab ? $this->configurable->tabLabel() : Str::headline($this->integration);
    }

    /**
     * @return list<Setting>
     */
    #[Computed]
    public function settings(): array
    {
        return $this->configurable->settings();
    }

    protected function load(PluginSettings $settings): void
    {
        $stored = $settings->all($this->configurable);

        foreach ($this->settings as $setting) {
            $this->values[$setting->key] = $setting->isSecret() ? '' : ($stored[$setting->key] ?? '');
            $this->secretsSet[$setting->key] = $setting->isSecret() && filled($stored[$setting->key] ?? null);
        }
    }

    public function save(PluginSettings $settings): void
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

        $settings->put($this->configurable, $values);

        $this->load($settings);

        Flux::toast(variant: 'success', text: __(':plugin settings saved.', ['plugin' => $this->label]));
    }

    /**
     * Remove a secret's value.
     */
    public function clearSecret(string $key, PluginSettings $settings): void
    {
        $this->authorize('administer');

        abort_unless(collect($this->settings)->contains(fn (Setting $setting): bool => $setting->key === $key && $setting->isSecret()), 404);

        $settings->put($this->configurable, [$key => null]);
        $this->load($settings);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:breadcrumbs class="mb-2">
            <flux:breadcrumbs.item :href="route('plugins')" wire:navigate>{{ __('Plugins') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('plugins', $category)" wire:navigate>{{ PluginCategory::from($category)->label() }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $this->label }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
        <div class="flex flex-wrap items-center gap-3">
            <flux:heading size="xl" level="1" class="tracking-tight">{{ __('Configure :plugin', ['plugin' => $this->label]) }}</flux:heading>
            @if (app(PluginSettings::class)->isConfigured($this->configurable))
                <flux:badge size="sm" color="emerald">{{ __('Configured') }}</flux:badge>
            @else
                <flux:badge size="sm" color="amber">{{ __('Not configured') }}</flux:badge>
            @endif
        </div>
        <flux:subheading class="max-w-3xl">{{ $this->configurable->settingsDescription() }}</flux:subheading>
    </div>

    <x-panel icon="cog-6-tooth" :heading="__('Settings')" :description="__('These apply to every server and site the plugin is used on.')" class="max-w-3xl">
        <form wire:submit="save" class="flex flex-col gap-5">
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
                        <flux:input wire:model="values.{{ $setting->key }}" :type="$setting->type === Setting::URL ? 'url' : 'text'" class="font-mono"
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
