<?php

use App\Actions\Sites\CreateSite;
use App\Models\Server;
use App\Models\Site;
use App\Sites\SiteSystemUser;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    public Server $server;

    public string $domain = '';

    public string $aliases = '';

    public string $web_directory = '/public';

    public string $php_version = '';

    public bool $isolated = true;

    public string $system_user = '';

    public bool $create_database = true;

    public string $database = '';

    public string $database_engine = '';

    public bool $zero_downtime = true;

    public bool $scheduler = true;

    /**
     * The name last suggested from the domain, so names the user typed themselves are never overwritten.
     */
    #[Locked]
    public string $suggested_name = '';

    public function mount(): void
    {
        $this->authorize('view', $this->server);
        abort_unless($this->server->isAppServer(), 404);

        $this->php_version = $this->server->default_php_version->value;
        $this->database_engine = $this->server->defaultDatabaseEngine()->value ?? '';
    }

    public function render(): mixed
    {
        return $this->view()->title(__('Sites').' · '.$this->server->name);
    }

    /**
     * @return Collection<int, Site>
     */
    #[Computed]
    public function sites(): Collection
    {
        return $this->server->sites()->with('latestDeployment', 'certificate')->orderBy('domain')->get();
    }

    /**
     * Suggest the system user and database names from the domain.
     */
    public function updatedDomain(): void
    {
        $domain = strtolower(trim($this->domain));
        $suggestion = $domain === '' ? '' : SiteSystemUser::suggest($domain, $this->server);

        foreach (['system_user', 'database'] as $field) {
            if ($this->{$field} === '' || $this->{$field} === $this->suggested_name) {
                $this->{$field} = $suggestion;
            }
        }

        $this->suggested_name = $suggestion;
    }

    /**
     * Create a new site on the server.
     */
    public function create(CreateSite $createSite): void
    {
        $this->authorize('update', $this->server);

        $createSite->fromInput($this->server, $this->only([
            'domain', 'aliases', 'web_directory', 'php_version', 'isolated', 'system_user', 'create_database', 'database', 'database_engine', 'zero_downtime', 'scheduler',
        ]));

        $this->reset('domain', 'aliases', 'isolated', 'system_user', 'create_database', 'database', 'zero_downtime', 'scheduler', 'suggested_name');
        $this->database_engine = $this->server->defaultDatabaseEngine()->value ?? '';
        Flux::modal('create-site')->close();
        Flux::toast(variant: 'success', text: __('Installing site.'));
    }
}; ?>

<div @if ($this->sites->contains(fn ($site) => $site->status->isBusy())) wire:poll.3s @endif>
    <x-pages::servers.layout :server="$server">
        <x-panel icon="globe-alt" :heading="__('Sites')" :description="__('Every site gets its own nginx config, PHP-FPM pool and deploy key.')" flush>
            <x-slot:actions>
                <flux:modal.trigger name="create-site">
                    <flux:button size="sm" variant="primary" icon="plus">{{ __('New site') }}</flux:button>
                </flux:modal.trigger>
            </x-slot:actions>

            @if ($this->sites->isEmpty())
                <x-empty-state icon="globe-alt" :heading="__('No sites yet')">
                    {{ __('Create a site, point its domain at :ip and install a repository to deploy.', ['ip' => $server->ip_address]) }}
                </x-empty-state>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Domain') }}</flux:table.column>
                        <flux:table.column>{{ __('Repository') }}</flux:table.column>
                        <flux:table.column>{{ __('PHP') }}</flux:table.column>
                        <flux:table.column>{{ __('Last deployment') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->sites as $site)
                            <flux:table.row :key="$site->id">
                                <flux:table.cell>
                                    <a href="{{ route('servers.sites.show', [$server, $site]) }}" wire:navigate class="group flex items-center gap-2.5 font-medium text-zinc-900 dark:text-white">
                                        @if ($site->certificate?->isActive())
                                            <flux:tooltip :content="__('SSL certificate active')">
                                                <flux:icon.lock-closed variant="micro" class="text-emerald-500" />
                                            </flux:tooltip>
                                        @else
                                            <flux:tooltip :content="__('No SSL certificate')">
                                                <flux:icon.lock-open variant="micro" class="text-zinc-400" />
                                            </flux:tooltip>
                                        @endif
                                        <span class="group-hover:text-ember-600 dark:group-hover:text-ember-400">{{ $site->domain }}</span>
                                        @if ($site->zero_downtime)
                                            <flux:tooltip :content="__('Zero downtime deployments')">
                                                <flux:icon.bolt variant="micro" class="text-sky-500" />
                                            </flux:tooltip>
                                        @endif
                                    </a>
                                </flux:table.cell>
                                <flux:table.cell class="font-mono text-xs">
                                    @if ($site->repository)
                                        {{ $site->repository }}<span class="text-ember-600 dark:text-ember-400">:{{ $site->branch }}</span>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="font-mono text-xs">{{ $site->php_version->value }}</flux:table.cell>
                                <flux:table.cell>
                                    @if ($site->latestDeployment)
                                        <x-status :color="$site->latestDeployment->status->color()" :label="$site->latestDeployment->created_at->diffForHumans()" />
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    <x-status :color="$site->status->color()" :label="$site->status->label()" />
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-panel>

        <flux:modal name="create-site" class="w-full max-w-lg">
            <form wire:submit="create" class="flex flex-col gap-6">
                <flux:heading size="lg">{{ __('New site') }}</flux:heading>

                <flux:input wire:model.live.debounce.400ms="domain" :label="__('Domain')" placeholder="example.com" required />
                <flux:input wire:model="aliases" :label="__('Aliases')" :description="__('Separate extra domains with commas or spaces.')" placeholder="www.example.com" />
                <flux:input wire:model="web_directory" :label="__('Web directory')" required />

                <flux:select wire:model="php_version" :label="__('PHP version')">
                    @foreach ($server->php_versions as $version)
                        <flux:select.option :value="$version->value">PHP {{ $version->value }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:checkbox wire:model="scheduler" :label="__('Laravel scheduler')" :description="__('Runs php artisan schedule:run every minute. It can be turned off later from the Queues & scheduler tab.')" />

                <flux:checkbox wire:model="zero_downtime" :label="__('Zero downtime deployments')" :description="__('Each deploy builds a new release and switches traffic to it atomically. This cannot be changed later, and Octane is not available for zero downtime sites.')" />

                <div class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-4 dark:border-white/10">
                    <flux:switch wire:model.live="isolated" :label="__('Isolate site')" :description="__('Runs as its own Linux user with its own PHP-FPM pool, so it cannot read other sites\' files. This cannot be changed later.')" align="left" />

                    @if ($isolated)
                        <flux:input wire:model="system_user" :label="__('System user')" placeholder="example" class="font-mono" required />
                    @endif
                </div>

                @if ($server->hasDatabase())
                    <div class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-4 dark:border-white/10">
                        <flux:switch wire:model.live="create_database" :label="__('Create database')" :description="$isolated
                            ? __('Creates a database and a user of the same name as the system user that can only access it, and writes the credentials into the site\'s .env.')
                            : __('Creates a database and a user of the same name that can only access it, and writes the credentials into the site\'s .env.')" align="left" />

                        @if ($create_database)
                            @if (count($server->databaseEngines()) > 1)
                                <flux:radio.group wire:model="database_engine" :label="__('Engine')" variant="segmented">
                                    @foreach ($server->databaseEngines() as $databaseEngine)
                                        <flux:radio :value="$databaseEngine->value" :label="$databaseEngine->label()" />
                                    @endforeach
                                </flux:radio.group>
                            @endif
                            <flux:input wire:model="database" :label="__('Database name')" placeholder="example" class="font-mono" required />
                        @endif
                    </div>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Create site') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    </x-pages::servers.layout>
</div>
