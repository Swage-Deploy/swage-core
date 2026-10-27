<?php

use App\Actions\Sites\ChangeSiteDomain;
use App\Actions\Sites\DeleteSite;
use App\Actions\Sites\SetOctane;
use App\Actions\Sites\UpdateSiteSettings;
use App\Enums\ResourceStatus;
use App\Models\Server;
use App\Models\Site;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component {
    public Server $server;

    public Site $site;

    public string $aliases = '';

    public string $web_directory = '';

    public string $php_version = '';

    public string $confirmation = '';

    public string $domain = '';

    public bool $keepOldDomain = true;

    public bool $octane = false;

    public function mount(): void
    {
        $this->authorize('view', $this->site);

        $this->aliases = implode(', ', $this->site->aliases ?? []);
        $this->domain = $this->site->domain;
        $this->web_directory = $this->site->web_directory;
        $this->php_version = $this->site->php_version->value;
        $this->octane = $this->site->octane;
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Settings').' · '.$this->site->domain);
    }

    public function save(UpdateSiteSettings $updateSiteSettings): void
    {
        $this->authorize('update', $this->site);

        $updateSiteSettings->handle($this->site, $this->only(['aliases', 'web_directory', 'php_version']));

        Flux::toast(variant: 'success', text: __('Updating site configuration.'));
    }

    /**
     * Change the domain the site answers to first.
     */
    public function changeDomain(ChangeSiteDomain $changeSiteDomain): void
    {
        $this->authorize('update', $this->site);

        $changeSiteDomain->handle($this->site, ['domain' => $this->domain, 'keep_old_domain' => $this->keepOldDomain]);

        $this->domain = $this->site->domain;
        $this->aliases = implode(', ', $this->site->aliases ?? []);
        $this->keepOldDomain = true;

        Flux::modal('change-domain')->close();
        Flux::toast(variant: 'success', text: $this->site->certificate?->isActive()
            ? __('Changing the domain and requesting a new certificate.')
            : __('Changing the domain.'));
    }

    /**
     * Switch the site between Octane and PHP-FPM.
     */
    public function updatedOctane(bool $enabled): void
    {
        $this->authorize('update', $this->site);

        try {
            app(SetOctane::class)->handle($this->site, $enabled);
        } catch (ValidationException $exception) {
            $this->octane = $this->site->octane;

            throw $exception;
        }

        Flux::toast(variant: 'success', text: $enabled ? __('Starting Octane.') : __('Switching back to PHP-FPM.'));
    }

    public function delete(DeleteSite $deleteSite): void
    {
        $this->authorize('delete', $this->site);

        $this->validate(['confirmation' => ['required', 'in:'.$this->site->domain]], [
            'confirmation.in' => __('Type the domain to confirm.'),
        ]);

        $deleteSite->handle($this->site);

        $this->redirectRoute('servers.sites', $this->server, navigate: true);
    }
}; ?>

<div @if ($site->status->isBusy()) wire:poll.3s @endif>
    <x-pages::sites.layout :site="$site">
        <div class="flex flex-col gap-6">
            <x-panel icon="adjustments-horizontal" :heading="__('Site settings')">
            <form wire:submit="save" class="flex flex-col gap-6">
                <flux:field>
                    <flux:label>{{ __('Domain') }}</flux:label>
                    <div class="flex items-center gap-2">
                        <flux:input :value="$site->domain" readonly class="font-mono" />
                        @can('update', $site)
                            <flux:modal.trigger name="change-domain">
                                <flux:button icon="pencil-square" :disabled="$site->status->isBusy()">{{ __('Change') }}</flux:button>
                            </flux:modal.trigger>
                        @endcan
                    </div>
                </flux:field>
                <flux:input wire:model="aliases" :label="__('Aliases')" :description="__('Separate extra domains with commas or spaces.')" />
                <flux:input wire:model="web_directory" :label="__('Web directory')" />
                <flux:select wire:model="php_version" :label="__('PHP version')">
                    @foreach ($server->php_versions as $version)
                        <flux:select.option :value="$version->value">PHP {{ $version->value }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div>
                    <flux:button type="submit" variant="primary" :disabled="$site->status->isBusy()">{{ __('Save') }}</flux:button>
                </div>
            </form>
            </x-panel>

            @can('update', $site)
                <livewire:pages::sites.environment :server="$server" :site="$site" />
            @endcan

            <x-panel :icon="$site->zero_downtime ? 'bolt' : 'arrow-path'" :heading="__('Deployment mode')">
            <section class="flex flex-col gap-4">
                <div>
                    <flux:text>
                        {{ $site->zero_downtime
                            ? __('Zero downtime: every deploy builds a new release and switches traffic to it atomically.')
                            : __('In place: every deploy pulls the latest commit into the live directory.') }}
                        {{ __('The mode is chosen when the site is created.') }}
                    </flux:text>
                </div>

                @if ($site->supportsOctane())
                    <flux:switch wire:model.live="octane" :label="__('Serve with Octane (FrankenPHP)')" :description="__('Requires laravel/octane in the application. Deploy the site at least once before enabling it.')" align="left" :disabled="$site->status->isBusy()" />
                    <flux:error name="octane" />
                @else
                    <flux:callout icon="information-circle" color="zinc">
                        <flux:callout.text>{{ __('Octane is not available for zero downtime sites. Octane workers keep the release they booted from in memory, so swapping releases underneath them is unsafe.') }}</flux:callout.text>
                    </flux:callout>
                @endif
            </section>
            </x-panel>

            @if ($site->private_redis)
                <x-panel icon="lock-closed" :heading="__('Redis')">
                    <flux:text>{{ __('The site has a Redis server of its own that only its user can reach, at :socket.', ['socket' => $site->redisSocket()]) }}</flux:text>
                </x-panel>
            @endif

            @can('delete', $site)
                <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-red-500/25 bg-red-500/[0.03] p-5">
                    <div class="max-w-md">
                        <flux:heading>{{ __('Delete site') }}</flux:heading>
                        <flux:text class="mt-0.5">{{ __('Removes the nginx config, every release, shared storage, workers, scheduled jobs and the repository\'s deploy key. Databases are kept.') }}</flux:text>
                    </div>

                    <flux:modal.trigger name="delete-site">
                        <flux:button variant="danger" icon="trash">{{ __('Delete site') }}</flux:button>
                    </flux:modal.trigger>
                </section>
            @endcan
        </div>

        <flux:modal name="change-domain" class="md:w-[28rem]">
            <form wire:submit="changeDomain" class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg">{{ __('Change domain') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('The site\'s files stay where they are, in :path, and its workers keep running. APP_URL follows the new domain when it names the old one.', ['path' => $site->path()]) }}</flux:text>
                </div>

                <flux:input wire:model="domain" :label="__('New domain')" placeholder="example.com" class="font-mono" />
                <flux:checkbox wire:model="keepOldDomain" :label="__('Keep :domain as an alias', ['domain' => $site->domain])" :description="__('Visitors and links using the old domain keep working.')" />

                @if ($site->certificate?->isActive())
                    <flux:callout icon="lock-closed" color="zinc">
                        <flux:callout.text>{{ __('Swage requests a new certificate that covers the new domain. The current one keeps serving until it is issued, so point the new domain at this server first.') }}</flux:callout.text>
                    </flux:callout>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Change domain') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="delete-site" class="md:w-96">
            {{-- Stop polling first: a refresh sent after the record is gone would fail with a 404. --}}
            <form wire:submit="delete" x-on:submit="$wire.$el.removeAttribute('wire:poll.3s')" class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete :domain?', ['domain' => $site->domain]) }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Type the domain to confirm.') }}</flux:text>
                </div>

                <flux:input wire:model="confirmation" :placeholder="$site->domain" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="danger">{{ __('Delete') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    </x-pages::sites.layout>
</div>
