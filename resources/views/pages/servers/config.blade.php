<?php

use App\Actions\Servers\ApplyConfigOverride;
use App\Enums\ConfigService;
use App\Exceptions\ScriptFailedException;
use App\Models\Server;
use App\Provisioning\ConfigMerger;
use App\Provisioning\ServiceConfigFiles;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    public Server $server;

    #[Url]
    public string $service = 'nginx';

    public string $override = '';

    public ?string $failureOutput = null;

    public function mount(): void
    {
        $this->authorize('view', $this->server);

        $this->selectService($this->service);
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Config').' · '.$this->server->name);
    }

    /**
     * Switch to another service, discarding any unsaved changes.
     */
    public function selectService(string $service): void
    {
        $selected = ConfigService::tryFrom($service);

        $this->service = $selected?->isAvailableOn($this->server) ? $selected->value : ConfigService::availableOn($this->server)[0]->value;
        $this->override = $this->server->configOverride($this->selectedService()) ?? '';
        $this->failureOutput = null;
    }

    public function selectedService(): ConfigService
    {
        return ConfigService::from($this->service);
    }

    #[Computed]
    public function configFiles(): ?ServiceConfigFiles
    {
        return $this->server->tuning ? ServiceConfigFiles::for($this->server) : null;
    }

    /**
     * Swage's config for the service, with the lines the draft overrides marked.
     *
     * @return list<array{line: string, overridden: bool}>
     */
    #[Computed]
    public function preview(): array
    {
        $service = $this->selectedService();

        return ConfigMerger::annotate(
            $this->configFiles->base($service),
            $this->override,
            $service->syntax(),
            $service === ConfigService::Nginx ? 'http' : null,
        );
    }

    #[Computed]
    public function hasUnsavedChanges(): bool
    {
        return rtrim(str_replace("\r\n", "\n", $this->override)) !== ($this->server->configOverride($this->selectedService()) ?? '');
    }

    /**
     * Write the custom config to the server. The previous config is restored if the service rejects it.
     */
    public function save(ApplyConfigOverride $applyOverride): void
    {
        $this->authorize('runAsRoot', $this->server);

        $this->validate(['override' => ['present', 'nullable', 'string', 'max:65535']]);

        $this->failureOutput = null;

        try {
            $applyOverride->handle($this->server, $this->selectedService(), $this->override);
        } catch (ScriptFailedException $exception) {
            $this->failureOutput = $exception->task->output;

            Flux::toast(variant: 'danger', text: __('The server rejected the config, so the previous config was kept.'));

            return;
        } catch (Throwable $exception) {
            $this->failureOutput = $exception->getMessage();

            Flux::toast(variant: 'danger', text: __('Unable to reach the server.'));

            return;
        }

        $this->override = $this->server->configOverride($this->selectedService()) ?? '';

        Flux::toast(variant: 'success', text: __('Custom :service config applied.', ['service' => $this->selectedService()->label()]));
    }

    /**
     * Discard unsaved changes.
     */
    public function revert(): void
    {
        $this->selectService($this->service);
    }
}; ?>

<div>
    <x-pages::servers.layout :server="$server">
        @if (! $this->configFiles)
            <flux:callout icon="information-circle" color="amber">
                <flux:callout.text>{{ __('This server has not been tuned yet. Re-optimize it from the overview page first.') }}</flux:callout.text>
            </flux:callout>
        @else
            @php($selected = $this->selectedService())

            <div class="grid items-start gap-6 lg:grid-cols-[220px_1fr]">
                <nav class="flex gap-1 overflow-x-auto rounded-xl border border-zinc-200 bg-white p-1.5 lg:flex-col dark:border-white/8 dark:bg-zinc-900/70" aria-label="{{ __('Services') }}">
                    @foreach (ConfigService::availableOn($server) as $option)
                        <button type="button" wire:click="selectService('{{ $option->value }}')" wire:key="service-{{ $option->value }}" @class([
                            'flex shrink-0 items-center gap-2.5 rounded-lg px-3 py-2 text-start text-sm transition',
                            'bg-ember-500/10 font-medium text-ember-700 dark:text-ember-400' => $option === $selected,
                            'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-white/5' => $option !== $selected,
                        ])>
                            <flux:icon :name="$option->icon()" variant="mini" class="size-4 shrink-0" />
                            <span class="flex-1 truncate">{{ $option->label() }}</span>
                            @if (filled($server->configOverride($option)))
                                <span class="size-1.5 rounded-full bg-ember-500" title="{{ __('Customized') }}"></span>
                            @endif
                        </button>
                    @endforeach
                </nav>

                <div class="flex min-w-0 flex-col gap-6">
                    <x-panel :icon="$selected->icon()" :heading="__('Custom :service', ['service' => $selected->label()])" :description="$selected->description()">
                        @can('runAsRoot', $server)
                            <x-slot:actions>
                                @if ($this->hasUnsavedChanges)
                                    <flux:button size="sm" variant="ghost" wire:click="revert">{{ __('Discard changes') }}</flux:button>
                                @endif
                                <flux:button size="sm" variant="primary" icon="check" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                                    <span wire:loading.remove wire:target="save">{{ __('Save & apply') }}</span>
                                    <span wire:loading wire:target="save">{{ __('Applying...') }}</span>
                                </flux:button>
                            </x-slot:actions>
                        @endcan

                        <div class="flex flex-col gap-4">
                            @cannot('runAsRoot', $server)
                                <flux:callout icon="lock-closed" color="zinc">
                                    <flux:callout.text>{{ __('Only the server\'s owner can change its services\' config, since the services read it as root.') }}</flux:callout.text>
                                </flux:callout>
                            @endcannot

                            <flux:textarea wire:model.live.debounce.400ms="override" :readonly="auth()->user()->cannot('runAsRoot', $server)" rows="14" spellcheck="false" :placeholder="__('Anything you add here takes precedence over the matching setting in Swage\'s config.')" class="!bg-zinc-950 font-mono text-xs !text-zinc-200 dark:!bg-black/40" />

                            <flux:text class="text-xs">
                                {{ __('When a setting here matches one in Swage\'s config, Swage\'s line is removed so yours applies. Swage checks the config before reloading and puts the previous files back if the service rejects it. Saving rewrites Swage\'s tuned files for this service, so make changes here rather than on the server.') }}
                            </flux:text>

                            @if ($failureOutput)
                                <x-terminal :title="__('Rejected by the server')">{{ $failureOutput }}</x-terminal>
                            @endif
                        </div>
                    </x-panel>

                    <x-panel icon="document-text" :heading="__('Swage\'s config')" :description="in_array($selected, [ConfigService::PhpFpmIni, ConfigService::PhpCliIni, ConfigService::PhpFpmPool]) ? __('Generated from this server\'s tuning. Shown for PHP :version; every installed version gets the same overrides.', ['version' => $server->default_php_version->value]) : __('Generated from this server\'s tuning. Struck-through lines are replaced by your custom config.')">
                        <x-config-preview :lines="$this->preview" :title="$this->configFiles->basePath($selected)" />
                    </x-panel>
                </div>
            </div>
        @endif
    </x-pages::servers.layout>
</div>
