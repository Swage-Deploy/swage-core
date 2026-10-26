<?php

use App\Actions\Sites\ApplySiteNginxOverride;
use App\Enums\ConfigSyntax;
use App\Enums\ResourceStatus;
use App\Exceptions\ScriptFailedException;
use App\Models\Server;
use App\Models\Site;
use App\Provisioning\ConfigMerger;
use App\Sites\SiteNginxConfig;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Server $server;

    public Site $site;

    public string $override = '';

    public ?string $failureOutput = null;

    public function mount(): void
    {
        $this->authorize('view', $this->site);

        $this->override = $this->site->nginx_override ?? '';
    }

    public function render(): mixed
    {
        return $this->view()->title('nginx · '.$this->site->domain);
    }

    /**
     * Swage's nginx config for the site, with the lines the draft overrides marked.
     *
     * @return list<array{line: string, overridden: bool}>
     */
    #[Computed]
    public function preview(): array
    {
        return ConfigMerger::annotate(SiteNginxConfig::for($this->site)->base(), $this->override, ConfigSyntax::Nginx, 'server');
    }

    #[Computed]
    public function hasUnsavedChanges(): bool
    {
        return rtrim(str_replace("\r\n", "\n", $this->override)) !== ($this->site->nginx_override ?? '');
    }

    /**
     * Write the custom nginx to the server. The previous config is restored if nginx rejects it.
     */
    public function save(ApplySiteNginxOverride $applyOverride): void
    {
        $this->authorize('editNginx', $this->site);

        if ($this->site->status !== ResourceStatus::Installed) {
            Flux::toast(variant: 'danger', text: __('Wait for the site to finish installing first.'));

            return;
        }

        $this->validate(['override' => ['present', 'nullable', 'string', 'max:65535']]);

        $this->failureOutput = null;

        try {
            $applyOverride->handle($this->site, $this->override);
        } catch (ScriptFailedException $exception) {
            $this->failureOutput = $exception->task->output;

            Flux::toast(variant: 'danger', text: __('nginx rejected the config, so the previous config was kept.'));

            return;
        } catch (Throwable $exception) {
            $this->failureOutput = $exception->getMessage();

            Flux::toast(variant: 'danger', text: __('Unable to reach the server.'));

            return;
        }

        $this->override = $this->site->nginx_override ?? '';

        Flux::toast(variant: 'success', text: __('Custom nginx applied.'));
    }

    /**
     * Discard unsaved changes.
     */
    public function revert(): void
    {
        $this->override = $this->site->nginx_override ?? '';
        $this->failureOutput = null;
    }
}; ?>

<div>
    <x-pages::sites.layout :site="$site">
        <div class="flex flex-col gap-6">
            <x-panel icon="code-bracket-square" :heading="__('Custom nginx')" :description="__('Directives and location blocks for this site\'s server block.')">
                @can('editNginx', $site)
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
                    <flux:textarea wire:model.live.debounce.400ms="override" :readonly="! auth()->user()->can('editNginx', $site)" rows="14" spellcheck="false" placeholder="client_max_body_size 512m;&#10;&#10;location /docs {&#10;    try_files $uri $uri/ =404;&#10;}" class="!bg-zinc-950 font-mono text-xs !text-zinc-200 dark:!bg-black/40" />

                    @cannot('editNginx', $site)
                        <flux:callout icon="lock-closed">
                            <flux:callout.text>{{ __('Only the server\'s owner and Managers can change custom nginx, since nginx reads it as root and it can reach anything on the server.') }}</flux:callout.text>
                        </flux:callout>
                    @endcannot

                    <flux:text class="text-xs">
                        {{ __('When a directive or location here matches one in Swage\'s config, Swage\'s is removed so yours applies. nginx checks the config before reloading, and the previous config is put back if it is rejected. It is kept when Swage rewrites the site\'s config, such as when a certificate is added.') }}
                    </flux:text>

                    @if ($failureOutput)
                        <x-terminal :title="__('Rejected by nginx')">{{ $failureOutput }}</x-terminal>
                    @endif
                </div>
            </x-panel>

            <x-panel icon="document-text" :heading="__('Swage\'s config')" :description="__('Struck-through lines are replaced by your custom nginx.')">
                <x-config-preview :lines="$this->preview" :title="'/etc/nginx/sites-available/'.$site->domain" />
            </x-panel>
        </div>
    </x-pages::sites.layout>
</div>
