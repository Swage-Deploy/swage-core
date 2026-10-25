<?php

use App\Models\Server;
use App\Models\Site;
use App\Ssh\RemoteShell;
use App\Ssh\Script;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component {
    public Server $server;

    public Site $site;

    public ?string $environment = null;

    public ?string $loadError = null;

    public bool $clearConfig = true;

    public function mount(): void
    {
        $this->authorize('update', $this->site);
    }

    /**
     * Read the site's shared .env file from the server. It holds the site's secrets, so it is only read, and sent to
     * the browser, once asked for.
     */
    public function reveal(RemoteShell $shell): void
    {
        $this->authorize('update', $this->site);

        $this->loadError = null;

        try {
            $result = $shell->run($this->server, 'cat '.escapeshellarg($this->site->path().'/shared/.env'), timeout: 20);
        } catch (Throwable $exception) {
            $this->loadError = $exception->getMessage();

            return;
        }

        if ($result->failed()) {
            $this->loadError = trim($result->output);

            return;
        }

        $this->environment = $result->output;
    }

    /**
     * Take the file's contents off the page again, discarding unsaved changes.
     */
    public function hide(): void
    {
        $this->environment = null;
        $this->loadError = null;
    }

    /**
     * Write the .env file back to the server, optionally clear the config cache, and reload PHP.
     */
    public function save(RemoteShell $shell): void
    {
        $this->authorize('update', $this->site);

        $this->validate([
            'environment' => ['present', 'nullable', 'string', 'max:65535'],
            'clearConfig' => ['boolean'],
        ]);

        $environment = rtrim(str_replace("\r\n", "\n", $this->environment ?? ''));

        $result = $shell->run($this->server, Script::render('sites.write-environment', [
            'site' => $this->site,
            'environment' => $environment,
            'delimiter' => 'SWAGE_ENV_'.Str::upper(Str::random(16)),
            'clearConfig' => $this->clearConfig,
        ]), timeout: 30);

        if ($result->failed()) {
            Flux::toast(variant: 'danger', text: __('Unable to save the environment: :output', ['output' => Str::limit(trim($result->output), 200)]));

            return;
        }

        Flux::toast(variant: 'success', text: $this->clearConfig
            ? __('Environment saved and config cache cleared.')
            : __('Environment saved. Run "php artisan config:clear" or deploy for cached config to pick it up.'));
    }
}; ?>

{{-- Shown on the site's settings page, to those who manage the site. --}}
<div>
    <x-panel icon="document-text" :heading="__('Environment')" :description="__('The .env file shared by every release of this site.')">
        @if ($environment !== null)
            <x-slot:actions>
                <flux:button size="sm" variant="ghost" icon="eye-slash" wire:click="hide">{{ __('Hide') }}</flux:button>
            </x-slot:actions>
        @endif
    <div>
        @if ($environment === null)
            <div class="flex flex-col items-start gap-4">
                @if ($loadError)
                    <flux:callout icon="x-circle" color="red" class="w-full">
                        <flux:callout.heading>{{ __('Unable to read the environment file') }}</flux:callout.heading>
                        <flux:callout.text>{{ $loadError }}</flux:callout.text>
                    </flux:callout>
                @endif
                <flux:text>{{ __('The environment holds the site\'s secrets, such as its database password and app key, so it stays hidden until you reveal it.') }}</flux:text>
                <flux:button icon="eye" wire:click="reveal" wire:loading.attr="disabled" wire:target="reveal">
                    <span wire:loading.remove wire:target="reveal">{{ __('Reveal environment') }}</span>
                    <span wire:loading wire:target="reveal">{{ __('Loading from server...') }}</span>
                </flux:button>
            </div>
        @else
            <form wire:submit="save" class="flex flex-col gap-4">
                <flux:textarea wire:model="environment" rows="24" class="!bg-zinc-950 font-mono text-xs !text-zinc-200 dark:!bg-black/40" spellcheck="false" />
                <flux:checkbox wire:model="clearConfig" :label="__('Run config:clear after saving')" :description="__('Clears the cached config so the application reads the new values.')" />
                <div>
                    <flux:button type="submit" variant="primary">{{ __('Save environment') }}</flux:button>
                </div>
            </form>
        @endif
    </div>
    </x-panel>
</div>
