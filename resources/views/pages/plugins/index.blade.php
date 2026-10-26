<?php

use App\Models\SelfUpdate;
use App\Models\Site;
use App\Models\SourceControlConnection;
use App\Plugins\IndexedPlugin;
use App\Plugins\Plugin;
use App\Plugins\PluginCategory;
use App\Plugins\PluginIndex;
use App\Plugins\PluginInstaller;
use App\Plugins\PluginKind;
use App\Plugins\PluginManifest;
use App\SelfUpdate\SelfUpdater;
use App\SourceControl\SourceControlProvider;
use App\SourceControl\SourceControlProviders;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Plugins')] class extends Component {
    /**
     * The tab that lists plugin installs, updates and removals instead of a category's plugins.
     */
    public const string ACTIVITY = 'activity';

    /**
     * The tab being viewed: one of the plugin categories, or the activity tab.
     */
    #[Locked]
    public string $category = 'system';

    /**
     * Newer versions found for installed plugins, keyed by plugin name. False when a plugin is up to date, and null
     * when its repository could not be reached.
     *
     * @var array<string, array{ref: string, commit: string}|false|null>
     */
    public array $updates = [];

    public bool $checkedForUpdates = false;

    public ?int $viewingOperationId = null;

    /**
     * The git repository of an unofficial plugin to install, and the tag or branch to install instead of its newest.
     */
    public string $repositoryUrl = '';

    public string $repositoryRef = '';

    /**
     * What the repository was found to hold when it was checked, which the install is held to.
     *
     * @var array{url: string, ref: string, commit: string}|null
     */
    #[Locked]
    public ?array $repositoryCheck = null;

    public function mount(?string $category = null): void
    {
        $this->authorize('administer');

        $category ??= PluginCategory::System->value;

        $this->category = $category === self::ACTIVITY ? $category : (PluginCategory::tryFrom($category)?->value ?? abort(404));
    }

    /**
     * The category being viewed, or null on the activity tab.
     */
    #[Computed]
    public function currentCategory(): ?PluginCategory
    {
        return PluginCategory::tryFrom($this->category);
    }

    /**
     * Installed plugins in the tab being viewed.
     *
     * @return list<Plugin>
     */
    #[Computed]
    public function installedInCategory(): array
    {
        return array_values(array_filter($this->plugins, fn (Plugin $plugin): bool => $plugin->kind->category() === $this->currentCategory));
    }

    /**
     * Listed plugins in the tab being viewed that are not installed yet.
     *
     * @return array<string, IndexedPlugin>
     */
    #[Computed]
    public function availableInCategory(): array
    {
        return array_filter($this->available, fn (IndexedPlugin $plugin): bool => $plugin->kind->category() === $this->currentCategory);
    }

    /**
     * @return array<string, SourceControlProvider>
     */
    #[Computed]
    public function providers(): array
    {
        return app(SourceControlProviders::class)->all();
    }

    /**
     * Providers that sites or connected accounts use but no installed plugin provides, such as after a plugin was
     * removed or before it is installed on an existing install.
     *
     * @return list<string>
     */
    #[Computed]
    public function missingProviders(): array
    {
        return Site::query()->whereNotNull('source_control')->where('source_control', '!=', SourceControlProviders::CUSTOM)->distinct()->pluck('source_control')
            ->merge(SourceControlConnection::query()->distinct()->pluck('provider'))
            ->unique()
            ->reject(fn (string $id): bool => isset($this->providers[$id]))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<Plugin>
     */
    #[Computed]
    public function plugins(): array
    {
        return app(PluginManifest::class)->all();
    }

    /**
     * The plugins the plugin index lists, and the error reading it, if any.
     *
     * @return array{plugins: array<string, IndexedPlugin>, error: ?string}
     */
    #[Computed]
    public function index(): array
    {
        try {
            return ['plugins' => app(PluginIndex::class)->all(), 'error' => null];
        } catch (RuntimeException $exception) {
            return ['plugins' => [], 'error' => $exception->getMessage()];
        }
    }

    /**
     * Listed plugins that are not installed yet.
     *
     * @return array<string, IndexedPlugin>
     */
    #[Computed]
    public function available(): array
    {
        $installed = array_map(fn (Plugin $plugin): string => $plugin->name, $this->plugins);

        return array_filter($this->index['plugins'], fn (IndexedPlugin $plugin): bool => ! in_array($plugin->name, $installed, true));
    }

    /**
     * Determine if an installed plugin is still listed in the index, from the repository it was installed from.
     */
    public function isListed(Plugin $plugin): bool
    {
        return ($this->index['plugins'][$plugin->name] ?? null)?->repository === $plugin->repository;
    }

    /**
     * The latest plugin installs, updates and removals, which the activity tab lists and every tab polls while one is
     * running.
     *
     * @return Collection<int, SelfUpdate>
     */
    #[Computed]
    public function operations(): Collection
    {
        return SelfUpdate::with('user')->whereNotNull('plugin_operation')->latest('id')->limit(25)
            ->get(['id', 'user_id', 'status', 'plugin_operation', 'created_at']);
    }

    #[Computed]
    public function viewingOperation(): ?SelfUpdate
    {
        return $this->viewingOperationId ? SelfUpdate::find($this->viewingOperationId) : null;
    }

    /**
     * Install a plugin the index lists.
     */
    public function install(string $name): void
    {
        $this->authorize('administer');

        abort_unless(isset($this->available[$name]), 404);

        $this->launch(['action' => 'install', 'name' => $name]);
    }

    /**
     * Look for a newer version tag, or new commits on the branch, of every installed plugin. Answers are remembered for
     * an hour, so opening the page again does not reach every repository.
     */
    public function checkForUpdates(PluginInstaller $installer, bool $fresh = false): void
    {
        $this->authorize('administer');

        if ($fresh) {
            rescue(fn () => app(PluginIndex::class)->all(fresh: true), report: false);
            unset($this->index, $this->available);
        }

        foreach (app(PluginManifest::class)->all() as $plugin) {
            // Official plugins are only offered updates while the index still lists them, from the same repository.
            if ($plugin->official && ! $this->isListed($plugin)) {
                unset($this->updates[$plugin->name]);

                continue;
            }

            $key = "plugin-update:{$plugin->name}:{$plugin->commit}";

            if ($fresh) {
                Cache::forget($key);
            }

            try {
                $this->updates[$plugin->name] = Cache::remember($key, now()->addHour(), fn () => $installer->availableUpdate($plugin) ?? false);
            } catch (RuntimeException) {
                $this->updates[$plugin->name] = null;
            }
        }

        $this->checkedForUpdates = true;
    }

    /**
     * Update one plugin to the newer version the last check found.
     */
    public function updatePlugin(string $name): void
    {
        $this->authorize('administer');

        app(PluginManifest::class)->find($name) ?? abort(404);

        abort_unless(is_array($this->updates[$name] ?? null), 409);

        $this->launch(['action' => 'update', 'names' => [$name]]);
    }

    /**
     * Update every plugin the last check found a newer version of, in a single rebuild.
     */
    public function updateAll(): void
    {
        $this->authorize('administer');

        $names = array_keys(array_filter($this->updates, 'is_array'));

        abort_if($names === [], 409);

        $this->launch(['action' => 'update', 'names' => $names]);
    }

    /**
     * Find the version an unofficial plugin would be installed at, so it can be confirmed before anything is fetched.
     */
    public function checkUnofficialPlugin(PluginInstaller $installer): void
    {
        $this->authorize('administer');

        $this->repositoryCheck = null;

        $validated = $this->validate([
            'repositoryUrl' => ['required', 'string', 'max:255', 'regex:'.PluginInstaller::REPOSITORY_PATTERN],
            'repositoryRef' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/D', 'not_regex:/^-|\.\./'],
        ], [
            'repositoryUrl.regex' => __('Enter a git URL, such as https://github.com/acme/swage-gitea or git@github.com:acme/swage-gitea.git.'),
        ]);

        try {
            $version = $installer->resolve($validated['repositoryUrl'], filled($validated['repositoryRef']) ? $validated['repositoryRef'] : null);
        } catch (RuntimeException $exception) {
            $this->addError('repositoryUrl', $exception->getMessage());

            return;
        }

        $this->repositoryCheck = ['url' => $validated['repositoryUrl'], ...$version];
    }

    /**
     * Forget the last check once the repository or version is changed, so a different one is checked before installing.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['repositoryUrl', 'repositoryRef'], true)) {
            $this->repositoryCheck = null;
        }
    }

    /**
     * Install the unofficial plugin at the commit the check found.
     */
    public function installUnofficialPlugin(): void
    {
        $this->authorize('administer');

        abort_if($this->repositoryCheck === null, 409);

        Flux::modal('install-from-repository')->close();

        $this->launch(['action' => 'install', ...$this->repositoryCheck]);

        $this->reset('repositoryUrl', 'repositoryRef', 'repositoryCheck');
    }

    public function removePlugin(string $name): void
    {
        $this->authorize('administer');

        $plugin = app(PluginManifest::class)->find($name) ?? abort(404);

        $this->launch(['action' => 'remove', 'name' => $plugin->name]);
    }

    /**
     * Start a plugin change in the background, which rebuilds Swage the way an update does.
     *
     * @param  array<string, string|list<string>>  $operation
     */
    protected function launch(array $operation): void
    {
        $updater = app(SelfUpdater::class);

        if (! $updater->isSupported()) {
            Flux::toast(variant: 'danger', text: __('Plugins can only be managed on installs created by the Swage installer.'));

            return;
        }

        if ($updater->isUpdating()) {
            Flux::toast(variant: 'warning', text: __('An update or plugin change is already running. Try again once it finishes.'));

            return;
        }

        $this->viewOperation($updater->launch(Auth::user(), $operation)->id);
        unset($this->operations);
    }

    public function viewOperation(int $operationId): void
    {
        $this->viewingOperationId = $operationId;

        Flux::modal('plugin-operation')->show();
    }
}; ?>

<div class="flex flex-col gap-6" @if ($this->operations->contains(fn ($operation) => $operation->status->isInProgress())) wire:poll.2s @endif>
    <div>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1" class="tracking-tight">{{ __('Plugins') }}</flux:heading>
                <flux:subheading>{{ __('Plugins extend Swage. Installing, updating or removing one rebuilds Swage with the change, and the running release stays live if the rebuild fails.') }}</flux:subheading>
            </div>
            <flux:modal.trigger name="install-from-repository">
                <flux:button size="sm" icon="code-bracket">{{ __('Install from a repository') }}</flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    @unless (app(App\SelfUpdate\SelfUpdater::class)->isSupported())
        <flux:callout icon="information-circle" color="zinc">
            <flux:callout.text>{{ __('This copy of Swage was not installed by the Swage installer, so plugins cannot be managed here. During development, require a plugin with Composer.') }}</flux:callout.text>
        </flux:callout>
    @endunless

    <flux:navbar scrollable aria-label="{{ __('Plugin categories') }}" class="-mb-2 -ms-2 border-b border-zinc-200 dark:border-white/8">
        @foreach (PluginCategory::cases() as $tab)
            @php($installedCount = count(array_filter($this->plugins, fn (Plugin $plugin): bool => $plugin->kind->category() === $tab)))
            <flux:navbar.item :icon="$tab->icon()" :href="route('plugins', $tab->value)" :current="$tab === $this->currentCategory" :badge="$installedCount ?: null" wire:navigate>{{ $tab->label() }}</flux:navbar.item>
        @endforeach
        <flux:navbar.item icon="clock" :href="route('plugins', self::ACTIVITY)" :current="$category === self::ACTIVITY" wire:navigate>{{ __('Activity') }}</flux:navbar.item>
    </flux:navbar>

    @if ($this->currentCategory === null)
        <x-panel icon="clock" :heading="__('Activity')" :description="__('The latest plugin installs, updates and removals. They also appear in the Updates history.')" flush>
            <flux:table>
                <flux:table.rows>
                    @forelse ($this->operations as $operation)
                        <flux:table.row :key="'operation-'.$operation->id">
                            <flux:table.cell>{{ $operation->title() }}</flux:table.cell>
                            <flux:table.cell>{{ $operation->user?->name ?? __('CLI') }}</flux:table.cell>
                            <flux:table.cell>{{ $operation->created_at->diffForHumans() }}</flux:table.cell>
                            <flux:table.cell><x-status :color="$operation->status->color()" :label="$operation->status->label()" /></flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:button size="sm" variant="ghost" icon="command-line" wire:click="viewOperation({{ $operation->id }})">{{ __('Output') }}</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5">{{ __('No plugins have been installed, updated or removed yet.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </x-panel>
    @else
        <flux:text class="max-w-3xl">{{ $this->currentCategory->description() }}</flux:text>

        @if ($this->currentCategory === PluginCategory::System)
            @if ($this->missingProviders !== [])
                <flux:callout icon="exclamation-triangle" color="amber">
                    <flux:callout.heading>{{ __('Sites or accounts use :providers, which no installed plugin provides', ['providers' => implode(', ', $this->missingProviders)]) }}</flux:callout.heading>
                    <flux:callout.text>{{ __('Those sites keep deploying from their clone URL, but their repositories cannot be reinstalled and quick deploy cannot be changed until the plugin is installed. Its connections and settings are kept and picked up again.') }}</flux:callout.text>
                </flux:callout>
            @endif

            <x-panel icon="folder-git-2" :heading="__('Source control')" :description="__('Configure each git host here, then people connect their accounts under Settings → Source control.')" flush>
                <flux:table>
                    <flux:table.rows>
                        @forelse ($this->providers as $provider)
                            <flux:table.row :key="'provider-'.$provider->id()">
                                <flux:table.cell class="font-medium">{{ $provider->name() }}</flux:table.cell>
                                <flux:table.cell>
                                    @if ($provider->isConfigured())
                                        <flux:badge size="sm" color="emerald">{{ __('Configured') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="amber">{{ __('Not configured') }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    @if ($provider instanceof App\SourceControl\HasSettings)
                                        <flux:button size="sm" icon="cog-6-tooth" :href="route('source-control.configure', $provider->id())" wire:navigate>{{ __('Configure :provider', ['provider' => $provider->name()]) }}</flux:button>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="3">{{ __('No source control plugins are installed. Install one, such as GitHub, from the list below.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </x-panel>
        @endif

        <x-panel icon="puzzle-piece" :heading="__('Installed')" flush>
            @if ($this->plugins !== [])
                <x-slot:actions>
                    <div wire:init="checkForUpdates" class="flex items-center gap-2">
                        @if (count(array_filter($updates, 'is_array')) > 1)
                            <flux:button size="sm" variant="primary" icon="arrow-up-circle" wire:click="updateAll"
                                wire:confirm="{{ __('Update every plugin with a newer version and rebuild Swage once with all of them?') }}">{{ __('Update all') }}</flux:button>
                        @endif
                        <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="checkForUpdates(true)" wire:loading.attr="disabled" wire:target="checkForUpdates">
                            {{ $checkedForUpdates ? __('Check again') : __('Checking...') }}
                        </flux:button>
                    </div>
                </x-slot:actions>
            @endif

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Plugin') }}</flux:table.column>
                    <flux:table.column>{{ __('Kind') }}</flux:table.column>
                    <flux:table.column>{{ __('Version') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->installedInCategory as $plugin)
                        @php($update = $updates[$plugin->name] ?? null)
                        <flux:table.row :key="'plugin-'.$plugin->name">
                            <flux:table.cell class="font-mono">
                                @if ($plugin->repositoryUrl())
                                    <flux:link :href="$plugin->repositoryUrl()" target="_blank" :title="$plugin->repository">{{ $plugin->name }}</flux:link>
                                @else
                                    <span title="{{ $plugin->repository }}">{{ $plugin->name }}</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" color="zinc">{{ $plugin->kind->label() }}</flux:badge>
                                @unless ($plugin->official)
                                    <flux:badge size="sm" color="amber" class="ms-1" :title="__('Installed from :repository. Swage has not reviewed it and does not check its signature.', ['repository' => $plugin->repository])">{{ __('Unofficial') }}</flux:badge>
                                @endunless
                            </flux:table.cell>
                            <flux:table.cell class="font-mono text-xs">
                                {{ $plugin->ref }} · {{ $plugin->shortCommit() }}
                                @if (is_array($update))
                                    <flux:badge size="sm" color="orange" class="ms-2">{{ $update['ref'] === $plugin->ref ? __('new commits') : __(':version available', ['version' => $update['ref']]) }}</flux:badge>
                                @elseif ($update === false)
                                    <flux:badge size="sm" color="emerald" class="ms-2">{{ __('up to date') }}</flux:badge>
                                @elseif ($checkedForUpdates && array_key_exists($plugin->name, $updates))
                                    <flux:badge size="sm" color="zinc" class="ms-2">{{ __('could not check') }}</flux:badge>
                                @elseif ($plugin->official && $this->index['error'] === null && ! $this->isListed($plugin))
                                    <flux:badge size="sm" color="zinc" class="ms-2">{{ __('no longer listed') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <div class="flex justify-end gap-1">
                                    @if (is_array($update))
                                        <flux:button size="sm" variant="primary" icon="arrow-up-circle" wire:click="updatePlugin('{{ $plugin->name }}')"
                                            wire:confirm="{{ __('Update :plugin to :ref (:commit) and rebuild Swage?', ['plugin' => $plugin->name, 'ref' => $update['ref'], 'commit' => substr($update['commit'], 0, 12)]) }}">{{ __('Update') }}</flux:button>
                                    @endif
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="removePlugin('{{ $plugin->name }}')"
                                        wire:confirm.danger="{{ $plugin->kind === PluginKind::SourceControl
                                            ? __('Remove :plugin and rebuild Swage without it? Sites using its provider keep deploying, but their deploy keys and webhooks can no longer be changed.', ['plugin' => $plugin->name])
                                            : __('Remove :plugin and rebuild Swage without it? Its settings are kept in the database, but nothing it set up is changed or removed.', ['plugin' => $plugin->name]) }}" />
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4">{{ __('No :category plugins installed.', ['category' => strtolower($this->currentCategory->label())]) }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </x-panel>

        <x-panel icon="squares-plus" :heading="__('Available')" :description="__('Official plugins, listed in the plugin index. Others can be installed from their git repository.')" flush>
            @if ($this->index['error'])
                <div class="px-5 py-4">
                    <flux:callout icon="exclamation-triangle" color="amber">
                        <flux:callout.text>{{ $this->index['error'] }}</flux:callout.text>
                    </flux:callout>
                </div>
            @else
                <flux:table>
                    <flux:table.rows>
                        @forelse ($this->availableInCategory as $listed)
                            <flux:table.row :key="'available-'.$listed->name">
                                <flux:table.cell>
                                    <div class="font-medium text-zinc-900 dark:text-white">{{ $listed->title }}</div>
                                    @if ($listed->repositoryUrl())
                                        <flux:link :href="$listed->repositoryUrl()" target="_blank" class="font-mono text-xs">{{ $listed->name }}</flux:link>
                                    @else
                                        <div class="font-mono text-xs text-zinc-500">{{ $listed->name }}</div>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell><flux:badge size="sm" color="zinc">{{ $listed->kind->label() }}</flux:badge></flux:table.cell>
                                <flux:table.cell class="max-w-md whitespace-normal text-sm text-zinc-600 dark:text-zinc-400">
                                    {{ $listed->description }}
                                    @if ($listed->homepage)
                                        <flux:link :href="$listed->homepage" target="_blank" class="ms-1">{{ __('More') }}</flux:link>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    <flux:button size="sm" variant="primary" icon="arrow-down-tray" wire:click="install('{{ $listed->name }}')"
                                        wire:confirm="{{ __('Install :plugin and rebuild Swage with it? It will have access to everything Swage stores.', ['plugin' => $listed->title]) }}">{{ __('Install') }}</flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                @php($listedInCategory = array_filter($this->index['plugins'], fn (IndexedPlugin $plugin): bool => $plugin->kind->category() === $this->currentCategory))
                                <flux:table.cell colspan="4">{{ $listedInCategory !== []
                                    ? __('Every listed :category plugin is installed.', ['category' => strtolower($this->currentCategory->label())])
                                    : __('The plugin index lists no :category plugins yet.', ['category' => strtolower($this->currentCategory->label())]) }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-panel>
    @endif

    <flux:modal name="install-from-repository" class="w-full max-w-lg">
        <form wire:submit="checkUnofficialPlugin" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Install from a repository') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Install a plugin that is not in the plugin index straight from its git repository. It is updated from that repository\'s version tags.') }}</flux:text>
            </div>

            <flux:callout icon="exclamation-triangle" color="amber">
                <flux:callout.text>{{ __('Swage has not reviewed this plugin and does not check its signature. It runs inside Swage with access to everything Swage stores, including the SSH keys of every server. Only install plugins from people you trust.') }}</flux:callout.text>
            </flux:callout>

            <flux:input wire:model="repositoryUrl" :label="__('Git URL')" placeholder="https://github.com/you/swage-plugin" class="font-mono" />
            <flux:input wire:model="repositoryRef" :label="__('Tag or branch')" :placeholder="__('The newest version tag')" class="font-mono" />

            @if ($repositoryCheck)
                <div class="rounded-lg border border-zinc-200 p-3 font-mono text-xs dark:border-white/10">
                    {{ __(':ref at :commit', ['ref' => $repositoryCheck['ref'], 'commit' => $repositoryCheck['commit']]) }}
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                @if ($repositoryCheck)
                    <flux:button type="button" variant="primary" icon="arrow-down-tray" wire:click="installUnofficialPlugin"
                        wire:confirm="{{ __('Install the plugin from :url at :ref and rebuild Swage with it? It will have access to everything Swage stores.', ['url' => $repositoryCheck['url'], 'ref' => $repositoryCheck['ref']]) }}">{{ __('Install') }}</flux:button>
                @else
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="checkUnofficialPlugin">{{ __('Check repository') }}</flux:button>
                @endif
            </div>
        </form>
    </flux:modal>

    <flux:modal name="plugin-operation" class="w-full max-w-4xl">
        @if ($selected = $this->viewingOperation)
            <div class="flex flex-col gap-4" @if ($selected->status->isInProgress()) wire:poll.2s @endif>
                <div class="flex items-center gap-3">
                    <flux:heading size="lg">{{ $selected->title() }}</flux:heading>
                    <x-status :color="$selected->status->color()" :label="$selected->status->label()" />
                </div>
                <x-terminal :title="$selected->title()">{{ $selected->output ?: __('Waiting for output...') }}</x-terminal>
            </div>
        @endif
    </flux:modal>
</div>
