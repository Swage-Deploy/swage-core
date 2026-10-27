<?php

use App\Actions\Sites\ApplySitePhpSettings;
use App\Enums\ResourceStatus;
use App\Exceptions\ScriptFailedException;
use App\Models\Server;
use App\Models\Site;
use App\Sites\SitePhpSettings;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Server $server;

    public Site $site;

    public string $memory_limit = '';

    public string $upload_max_filesize = '';

    public string $max_execution_time = '';

    public string $max_input_vars = '';

    /**
     * Other PHP settings, one "name = value" per line.
     */
    public string $directives = '';

    public ?string $failureOutput = null;

    public function mount(): void
    {
        $this->authorize('view', $this->site);

        $this->fillSettings();
    }

    public function render(): mixed
    {
        return $this->view()->title('PHP · '.$this->site->domain);
    }

    protected function fillSettings(): void
    {
        $settings = SitePhpSettings::for($this->site);

        $this->memory_limit = $settings->get('memory_limit');
        $this->upload_max_filesize = $settings->get('upload_max_filesize');
        $this->max_execution_time = $settings->get('max_execution_time');
        $this->max_input_vars = $settings->get('max_input_vars');
        $this->directives = $settings->directivesText();
    }

    /**
     * Whether the site runs in its PHP-FPM pool, which is where its PHP settings are written. Octane serves requests itself.
     */
    #[Computed]
    public function supportsSettings(): bool
    {
        return ! $this->site->octane;
    }

    /**
     * Write the site's PHP settings to its pool and nginx config. The previous config is kept if either is rejected.
     */
    public function save(ApplySitePhpSettings $applyPhpSettings): void
    {
        $this->authorize('update', $this->site);

        if (! $this->supportsSettings) {
            return;
        }

        if ($this->site->status !== ResourceStatus::Installed) {
            Flux::toast(variant: 'danger', text: __('Wait for the site to finish installing first.'));

            return;
        }

        $validated = $this->validate(SitePhpSettings::rules(), [
            'memory_limit.regex' => __('Use a size such as 512M or 1G, or -1 for no limit.'),
            'upload_max_filesize.regex' => __('Use a size such as 100M or 1G.'),
        ]);

        $settings = SitePhpSettings::fromFields($validated);

        $this->failureOutput = null;

        try {
            $applyPhpSettings->handle($this->site, $settings);
        } catch (ScriptFailedException $exception) {
            $this->failureOutput = trim($exception->task->output ?? '');

            Flux::toast(variant: 'danger', text: __('The settings were rejected, so the previous settings were kept.'));

            return;
        } catch (Throwable) {
            Flux::toast(variant: 'danger', text: __('Unable to reach the server.'));

            return;
        }

        $this->fillSettings();

        Flux::toast(variant: 'success', text: __('PHP settings applied.'));
    }
}; ?>

<div>
    <x-pages::sites.layout :site="$site">
        <div class="flex flex-col gap-6">
            <x-panel icon="cpu-chip" :heading="__('PHP settings')" :description="__('For this site\'s web requests. Leave a field empty to use the server\'s setting. Queue workers and the scheduler keep the server\'s settings.')">
                @if ($this->supportsSettings)
                    <form wire:submit="save" class="flex flex-col gap-6">
                        {{-- Each field spans four rows of the grid (label, description, input, error) and shares them through
                             subgrid, so inputs line up across a row however many lines each description wraps to. --}}
                        <div class="grid gap-x-4 gap-y-6 sm:grid-cols-2 xl:grid-cols-4">
                            <flux:field variant="bare" class="row-span-4 grid grid-rows-subgrid gap-y-2">
                                <flux:label>{{ __('Memory limit') }}</flux:label>
                                <flux:description>{{ __('Such as 512M or 1G, or -1 for no limit.') }}</flux:description>
                                <flux:input wire:model="memory_limit" class="font-mono" placeholder="512M" />
                                <flux:error name="memory_limit" />
                            </flux:field>
                            <flux:field variant="bare" class="row-span-4 grid grid-rows-subgrid gap-y-2">
                                <flux:label>{{ __('Max upload size') }}</flux:label>
                                <flux:description>{{ __('Also sets post_max_size and nginx\'s client_max_body_size.') }}</flux:description>
                                <flux:input wire:model="upload_max_filesize" class="font-mono" placeholder="100M" />
                                <flux:error name="upload_max_filesize" />
                            </flux:field>
                            <flux:field variant="bare" class="row-span-4 grid grid-rows-subgrid gap-y-2">
                                <flux:label>{{ __('Max execution time (s)') }}</flux:label>
                                <flux:description>{{ __('nginx waits at least this long for PHP.') }}</flux:description>
                                <flux:input wire:model="max_execution_time" type="number" min="1" max="3600" placeholder="30" />
                                <flux:error name="max_execution_time" />
                            </flux:field>
                            <flux:field variant="bare" class="row-span-4 grid grid-rows-subgrid gap-y-2">
                                <flux:label>{{ __('Max input vars') }}</flux:label>
                                <flux:description>{{ __('Raise it for forms with many fields.') }}</flux:description>
                                <flux:input wire:model="max_input_vars" type="number" min="1" placeholder="1000" />
                                <flux:error name="max_input_vars" />
                            </flux:field>
                        </div>

                        <flux:textarea wire:model="directives" rows="4" spellcheck="false" :label="__('Other settings')" :description="__('One name = value per line, such as display_errors = Off.')" placeholder="max_input_time = 120&#10;session.gc_maxlifetime = 7200" class="font-mono text-xs" />

                        @if ($failureOutput)
                            <x-terminal :title="__('Rejected')">{{ $failureOutput }}</x-terminal>
                        @endif

                        <div>
                            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save" :disabled="$site->status->isBusy()">
                                <span wire:loading.remove wire:target="save">{{ __('Save & apply') }}</span>
                                <span wire:loading wire:target="save">{{ __('Applying...') }}</span>
                            </flux:button>
                        </div>
                    </form>
                @else
                    <flux:callout icon="information-circle" color="zinc">
                        <flux:callout.text>
                            {{ $site->octane
                                ? __('Octane serves this site, so its PHP settings come from the server\'s php.ini.')
                                : __('This site shares the server\'s PHP pool, so its PHP settings come from the server\'s php.ini. Sites created with their own system user get a pool of their own.') }}
                        </flux:callout.text>
                    </flux:callout>
                @endif
            </x-panel>
        </div>
    </x-pages::sites.layout>
</div>
