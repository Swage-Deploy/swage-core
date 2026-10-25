<?php

use App\Actions\Sites\EnablePrivateRedis;
use App\Actions\Sites\SetOctane;
use App\Exceptions\ScriptFailedException;
use App\Enums\ResourceStatus;
use App\Jobs\InstallDaemon;
use App\Jobs\InstallScheduledJob;
use App\Jobs\RemoveSite;
use App\Jobs\UpdateSiteConfiguration;
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

    public bool $octane = false;

    public function mount(): void
    {
        $this->authorize('view', $this->site);

        $this->aliases = implode(', ', $this->site->aliases ?? []);
        $this->web_directory = $this->site->web_directory;
        $this->php_version = $this->site->php_version->value;
        $this->octane = $this->site->octane;
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Settings').' · '.$this->site->domain);
    }

    public function save(): void
    {
        $this->authorize('update', $this->site);

        $validated = $this->validate([
            'aliases' => ['nullable', 'string', 'max:1000'],
            'web_directory' => ['required', 'string', 'max:100', 'regex:/^\/[A-Za-z0-9._\/-]*$/D', 'not_regex:/\.\./'],
            'php_version' => ['required', Rule::in($this->server->php_versions->map->value)],
        ]);

        $aliases = collect(preg_split('/[\s,]+/', strtolower($validated['aliases'] ?? ''), flags: PREG_SPLIT_NO_EMPTY))
            ->reject(fn (string $alias): bool => $alias === $this->site->domain)
            ->unique()
            ->values();

        foreach ($aliases as $alias) {
            if (! preg_match('/^(\*\.)?(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/D', $alias)) {
                $this->addError('aliases', __(':alias is not a valid domain name.', ['alias' => $alias]));

                return;
            }
        }

        $previousBinary = $this->site->php_version->binary();

        $this->site->update([
            'aliases' => $aliases->all(),
            'web_directory' => $validated['web_directory'],
            'php_version' => $validated['php_version'],
            'status' => ResourceStatus::Installing,
        ]);

        UpdateSiteConfiguration::dispatch($this->site);

        if ($previousBinary !== $this->site->php_version->binary()) {
            $this->switchPhpBinary($previousBinary, $this->site->php_version->binary());
        }

        Flux::toast(variant: 'success', text: __('Updating site configuration.'));
    }

    /**
     * Point the site's workers and scheduler at its new PHP version.
     */
    protected function switchPhpBinary(string $from, string $to): void
    {
        foreach ($this->site->daemons as $daemon) {
            $daemon->update(['command' => preg_replace('/^'.preg_quote($from, '/').' /', "{$to} ", $daemon->command)]);
            InstallDaemon::dispatch($daemon);
        }

        foreach ($this->site->scheduledJobs as $scheduledJob) {
            $scheduledJob->update(['command' => preg_replace('/^'.preg_quote($from, '/').' /', "{$to} ", $scheduledJob->command)]);
            InstallScheduledJob::dispatch($scheduledJob);
        }
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

    /**
     * Move an isolated site from the shared Redis server to one only it can reach.
     */
    public function enablePrivateRedis(EnablePrivateRedis $enablePrivateRedis): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->supportsPrivateRedis() || $this->site->private_redis) {
            return;
        }

        try {
            $enablePrivateRedis->handle($this->site);
        } catch (ScriptFailedException) {
            Flux::toast(variant: 'danger', text: __('The private Redis server could not be started. Check the activity log.'));

            return;
        } catch (Throwable) {
            Flux::toast(variant: 'danger', text: __('Unable to reach the server.'));

            return;
        }

        Flux::modal('private-redis')->close();
        Flux::toast(variant: 'success', text: __('The site now uses its own Redis server.'));
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->site);

        $this->validate(['confirmation' => ['required', 'in:'.$this->site->domain]], [
            'confirmation.in' => __('Type the domain to confirm.'),
        ]);

        $this->site->update(['status' => ResourceStatus::Removing]);

        RemoveSite::dispatch($this->site);

        $this->redirectRoute('servers.sites', $this->server, navigate: true);
    }
}; ?>

<div @if ($site->status->isBusy()) wire:poll.3s @endif>
    <x-pages::sites.layout :site="$site">
        <div class="flex flex-col gap-6">
            <x-panel icon="adjustments-horizontal" :heading="__('Site settings')">
            <form wire:submit="save" class="flex flex-col gap-6">
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

            @if ($site->supportsPrivateRedis())
                <x-panel icon="lock-closed" :heading="__('Redis')">
                    @if ($site->private_redis)
                        <flux:text>{{ __('The site has a Redis server of its own that only its user can reach, at :socket.', ['socket' => $site->redisSocket()]) }}</flux:text>
                    @else
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <flux:text class="max-w-xl">{{ __('The site shares the server\'s Redis with other sites, which can read and change its cache, sessions and queued jobs. Give it a private Redis server to wall it off.') }}</flux:text>

                            <flux:modal.trigger name="private-redis">
                                <flux:button size="sm" icon="lock-closed">{{ __('Use private Redis') }}</flux:button>
                            </flux:modal.trigger>
                        </div>
                    @endif
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

        <flux:modal name="private-redis" class="md:w-[28rem]">
            <div class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg">{{ __('Move to a private Redis server?') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('The site starts with an empty Redis: cached data and sessions stored in Redis are lost, so users may be signed out, and queued jobs that have not run yet stay behind in the shared server. Pick a quiet moment, ideally with the queue empty.') }}</flux:text>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" wire:click="enablePrivateRedis">{{ __('Move to private Redis') }}</flux:button>
                </div>
            </div>
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
