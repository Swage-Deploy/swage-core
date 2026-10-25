<?php

use App\Actions\Sites\InstallSiteRepository;
use App\Actions\Sites\QueueDeployment;
use App\Actions\Sites\SetQuickDeploy;
use App\Deployments\DeployScript;
use App\Enums\ResourceStatus;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use App\SourceControl\SourceControlClient;
use App\SourceControl\SourceControlException;
use App\SourceControl\SourceControlProvider;
use App\SourceControl\SourceControlProviders;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Server $server;

    public Site $site;

    /**
     * Where the repository comes from: a connected provider's id, or "custom" for a clone URL.
     */
    public string $source = '';

    /**
     * The account, organization or group the repository is chosen from.
     */
    public string $owner = '';

    /**
     * The repository's full path on the provider, or its clone URL when the source is custom.
     */
    public string $repository = '';

    public string $branch = '';

    public string $deploy_script = '';

    public bool $quick_deploy = false;

    public ?int $viewingDeploymentId = null;

    public function mount(): void
    {
        $this->authorize('view', $this->site);

        $this->source = $this->site->source_control ?? (array_key_first($this->connectedProviders) ?? SourceControlProviders::CUSTOM);
        $this->repository = $this->site->repository ?? '';
        $this->owner = $this->site->usesCustomRepository() ? '' : Str::beforeLast($this->repository, '/');
        $this->branch = $this->site->branch ?? '';
        $this->deploy_script = $this->site->deploy_script ?? DeployScript::default();
        $this->quick_deploy = (bool) $this->site->quick_deploy;
    }

    public function render(): mixed
    {
        return $this->view()->title($this->site->domain);
    }

    /**
     * The providers the signed-in user has connected an account to.
     *
     * @return array<string, SourceControlProvider>
     */
    #[Computed]
    public function connectedProviders(): array
    {
        // Repositories are installed through the server owner's account, so only the owner gets to choose from theirs.
        if (! Auth::user()->can('useSourceControl', $this->site)) {
            return [];
        }

        return app(SourceControlProviders::class)->connectedFor(Auth::user());
    }

    /**
     * The accounts and organizations of the chosen provider.
     *
     * @return list<string>
     */
    #[Computed]
    public function owners(): array
    {
        return $this->cached('owners', fn (SourceControlClient $client): array => $client->owners()) ?? [];
    }

    /**
     * The repositories of the chosen owner, which are only fetched once an owner is chosen.
     *
     * @return list<string>
     */
    #[Computed]
    public function repositories(): array
    {
        if (! in_array($this->owner, $this->owners, true)) {
            return [];
        }

        return $this->cached("repositories:{$this->owner}", fn (SourceControlClient $client): array => $client->repositories($this->owner)) ?? [];
    }

    /**
     * Fetch a listing from the chosen provider as the signed-in user, cached briefly for their connection.
     *
     * @template T
     *
     * @param  Closure(SourceControlClient): T  $fetch
     * @return T|null
     */
    protected function cached(string $name, Closure $fetch): mixed
    {
        $connection = isset($this->connectedProviders[$this->source]) ? Auth::user()->sourceControlConnection($this->source) : null;

        if ($connection === null) {
            return null;
        }

        $key = "source-control:{$connection->id}:".hash('xxh128', $connection->token).":{$name}";

        return rescue(
            fn () => Cache::remember($key, now()->addMinutes(5), fn () => $fetch(app(SourceControlProviders::class)->get($this->source)->client($connection))),
            report: false,
        );
    }

    public function updatedSource(): void
    {
        $this->reset('owner', 'repository', 'branch');
        unset($this->owners, $this->repositories);
    }

    public function updatedOwner(): void
    {
        $this->reset('repository', 'branch');
        unset($this->repositories);
    }

    /**
     * @return Collection<int, Deployment>
     */
    #[Computed]
    public function deployments(): Collection
    {
        return $this->site->deployments()->latest()->limit(20)
            ->get(['id', 'site_id', 'status', 'triggered_by', 'commit_hash', 'commit_message', 'commit_author', 'started_at', 'finished_at', 'created_at']);
    }

    #[Computed]
    public function viewingDeployment(): ?Deployment
    {
        return $this->viewingDeploymentId ? $this->site->deployments()->find($this->viewingDeploymentId) : null;
    }

    public function updatedRepository(string $repository): void
    {
        if (in_array($repository, $this->repositories, true)) {
            $this->branch = rescue(
                fn () => app(SourceControlProviders::class)->clientFor(Auth::user(), $this->source)->defaultBranch($repository),
                'main',
                report: false,
            );
        }
    }

    public function installRepository(InstallSiteRepository $installSiteRepository): void
    {
        $this->authorize('update', $this->site);

        if ($this->source !== SourceControlProviders::CUSTOM) {
            $this->authorize('useSourceControl', $this->site);
        }

        $installSiteRepository->handle($this->site, Auth::user(), $this->only(['source', 'repository', 'branch']));

        Flux::toast(variant: 'success', text: __('Installing repository.'));
    }

    public function deploy(QueueDeployment $queueDeployment): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->hasRepository() || $this->site->status !== ResourceStatus::Installed) {
            Flux::toast(variant: 'danger', text: __('Install a repository before deploying.'));

            return;
        }

        $queueDeployment->handle($this->site, Auth::user()->name);

        unset($this->deployments);
    }

    public function saveDeployScript(): void
    {
        $this->authorize('update', $this->site);

        $this->validate(['deploy_script' => ['required', 'string', 'max:20000']]);

        $this->site->update(['deploy_script' => $this->deploy_script]);

        Flux::toast(variant: 'success', text: __('Deploy script saved.'));
    }

    public function updatedQuickDeploy(bool $enabled): void
    {
        $this->authorize('useSourceControl', $this->site);

        abort_unless($this->site->supportsQuickDeploy(), 409);

        try {
            app(SetQuickDeploy::class)->handle($this->site, $enabled);
        } catch (SourceControlException $exception) {
            $this->quick_deploy = (bool) $this->site->quick_deploy;

            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: $enabled ? __('Pushes to :branch will now deploy.', ['branch' => $this->site->branch]) : __('Quick deploy disabled.'));
    }

    public function viewDeployment(int $deploymentId): void
    {
        $this->viewingDeploymentId = $deploymentId;

        Flux::modal('deployment-output')->show();
    }
}; ?>

<div @if ($site->status->isBusy() || $this->deployments->contains(fn ($deployment) => $deployment->status->isInProgress())) wire:poll.2s @endif>
    <x-pages::sites.layout :site="$site">
        <div class="flex flex-col gap-6">
            @if (! $site->hasRepository() || $site->status === ResourceStatus::Failed && ! $site->hasDeployKey())
                <x-panel icon="folder-git-2" :heading="__('Install repository')" :description="__('This server clones the repository with a read-only deploy key of its own.')">
                    @if ($site->status === ResourceStatus::Failed && $site->install_error)
                        <flux:callout icon="x-circle" color="red" class="mb-6 max-w-xl">
                            <flux:callout.heading>{{ __('The repository could not be installed') }}</flux:callout.heading>
                            <flux:callout.text>{{ $site->install_error }}</flux:callout.text>
                        </flux:callout>
                    @endif
                    <form wire:submit="installRepository" class="flex max-w-xl flex-col gap-4">
                        <flux:select wire:model.live="source" :label="__('Source')">
                            @foreach ($this->connectedProviders as $provider)
                                <flux:select.option :value="$provider->id()">{{ $provider->name() }}</flux:select.option>
                            @endforeach
                            <flux:select.option value="custom">{{ __('Custom git repository') }}</flux:select.option>
                        </flux:select>

                        @if ($source === 'custom')
                            <flux:input wire:model="repository" :label="__('Clone URL')" placeholder="git@git.example.com:team/app.git" class="font-mono"
                                :description="__('Any git host reachable over SSH. Swage shows a deploy key to add to the repository once it is installed.')" />
                        @else
                            <flux:select wire:model.live="owner" wire:key="owners-{{ $source }}" :label="__('Account or organization')" :placeholder="__('Choose an account...')"
                                :description="__('Missing one? Reconnect from your source control settings to grant access to it.')">
                                @foreach ($this->owners as $account)
                                    <flux:select.option :value="$account">{{ $account }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:select wire:model.live="repository" wire:key="repositories-{{ $source }}-{{ $owner }}" :label="__('Repository')" :placeholder="$owner === '' ? __('Choose an account first') : __('Choose a repository...')" :disabled="$owner === ''">
                                @foreach ($this->repositories as $name)
                                    <flux:select.option :value="$name">{{ Str::after($name, $owner.'/') }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif

                        <flux:input wire:model="branch" :label="__('Branch')" placeholder="main" />
                        <flux:error name="source" />
                        <div class="flex flex-wrap items-center gap-3">
                            <flux:button type="submit" variant="primary" icon="arrow-down-tray">{{ __('Install repository') }}</flux:button>
                            @cannot('useSourceControl', $site)
                                <flux:text class="text-sm">{{ __('Only the server\'s owner can install from a connected account such as GitHub.') }}</flux:text>
                            @elseif ($this->connectedProviders === [] && ! app(App\Actions\Users\Impersonation::class)->isImpersonating())
                                <flux:link :href="route('source-control.edit')" wire:navigate class="text-sm">{{ __('Connect a source control account') }}</flux:link>
                            @endcannot
                        </div>
                    </form>
                </x-panel>
            @else
                @php($latest = $this->deployments->first())

                <section class="relative isolate flex flex-wrap items-center justify-between gap-6 overflow-hidden rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-white/8 dark:bg-zinc-900/70">
                    <div class="absolute -bottom-20 -left-10 -z-10 size-48 rounded-full bg-ember-500/10 blur-3xl"></div>
                    <div class="flex flex-col gap-1">
                        <flux:text class="text-xs font-medium tracking-wider uppercase">{{ __('Latest deployment') }}</flux:text>
                        @if ($latest)
                            <div class="flex flex-wrap items-center gap-3">
                                <x-status :color="$latest->status->color()" :label="$latest->status->label()" />
                                @if ($latest->commit_hash)
                                    @if ($commitUrl = $site->commitUrl($latest->commit_hash))
                                        <a href="{{ $commitUrl }}" target="_blank" rel="noopener" class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-xs underline-offset-2 hover:underline dark:bg-white/5">{{ $latest->shortHash() }}</a>
                                    @else
                                        <span class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-xs dark:bg-white/5">{{ $latest->shortHash() }}</span>
                                    @endif
                                @endif
                                <span class="text-sm text-zinc-500">{{ $latest->created_at->diffForHumans() }}</span>
                            </div>
                        @else
                            <span class="text-sm text-zinc-500">{{ __('Never deployed') }}</span>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-6">
                        @if ($site->supportsQuickDeploy())
                            @can('useSourceControl', $site)
                                <flux:switch wire:model.live="quick_deploy" :label="__('Quick deploy')" :description="__('Deploy on push to :branch', ['branch' => $site->branch])" align="left" />
                            @else
                                <div class="text-sm">
                                    <div class="font-medium text-zinc-900 dark:text-white">{{ $site->quick_deploy ? __('Quick deploy is on') : __('Quick deploy is off') }}</div>
                                    <div class="text-zinc-500">{{ __('Only the server\'s owner can change it.') }}</div>
                                </div>
                            @endcan
                        @endif
                        <flux:button variant="primary" icon="rocket-launch" wire:click="deploy" :disabled="$site->status !== ResourceStatus::Installed">{{ __('Deploy now') }}</flux:button>
                    </div>
                </section>

                @if ($site->usesCustomRepository())
                    <x-panel icon="key" :heading="__('Deploy key')" :description="__('Add this key to :repository as a read-only deploy key, so this server can clone it. To deploy on push, call the deploy trigger URL below from your git host or CI.', ['repository' => $site->repository])">
                        <flux:input readonly copyable :value="$site->deploy_key_public" class="font-mono" />
                    </x-panel>
                @endif

                <x-panel icon="rocket-launch" :heading="__('Deployments')" flush>
                    @if ($this->deployments->isEmpty())
                        <x-empty-state icon="rocket-launch" :heading="__('No deployments yet')">
                            {{ __('Press Deploy now, push with quick deploy enabled, or call the trigger URL from CI.') }}
                        </x-empty-state>
                    @else
                        <ol class="divide-y divide-zinc-100 dark:divide-white/5">
                            @foreach ($this->deployments as $deployment)
                                <li wire:key="deployment-{{ $deployment->id }}" class="group flex items-center gap-4 px-5 py-3 transition hover:bg-zinc-50 dark:hover:bg-white/2">
                                    <x-status :color="$deployment->status->color()" class="w-20" :label="$deployment->status->label()" />

                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            @if ($deployment->commit_hash)
                                                @if ($commitUrl = $site->commitUrl($deployment->commit_hash))
                                                    <a href="{{ $commitUrl }}" target="_blank" rel="noopener" class="shrink-0 rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-600 underline-offset-2 hover:underline dark:bg-white/5 dark:text-zinc-300">{{ $deployment->shortHash() }}</a>
                                                @else
                                                    <span class="shrink-0 rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-600 dark:bg-white/5 dark:text-zinc-300">{{ $deployment->shortHash() }}</span>
                                                @endif
                                            @endif
                                            <span class="truncate text-sm text-zinc-900 dark:text-zinc-100">{{ $deployment->commit_message ?? __('Deployment #:id', ['id' => $deployment->id]) }}</span>
                                        </div>
                                        <div class="mt-0.5 text-xs text-zinc-500">
                                            {{ $deployment->commit_author ? $deployment->commit_author.' · ' : '' }}{{ __('triggered by :name', ['name' => $deployment->triggered_by]) }}
                                        </div>
                                    </div>

                                    <div class="hidden shrink-0 text-end text-xs text-zinc-500 sm:block">
                                        <div>{{ $deployment->created_at->diffForHumans() }}</div>
                                        @if ($deployment->durationInSeconds() !== null)
                                            <div class="font-mono">{{ $deployment->durationInSeconds() }}s</div>
                                        @endif
                                    </div>

                                    <flux:button size="sm" variant="ghost" icon="command-line" wire:click="viewDeployment({{ $deployment->id }})">{{ __('Output') }}</flux:button>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </x-panel>

                <x-panel icon="code-bracket-square" :heading="__('Deploy script')">
                    <x-slot:actions>
                        <flux:button size="sm" variant="primary" wire:click="saveDeployScript">{{ __('Save script') }}</flux:button>
                    </x-slot:actions>

                    <div class="flex flex-col gap-4">
                        <flux:text>
                            {{ __('Runs as :user. Macros:', ['user' => $site->systemUser()]) }}
                            @if ($site->zero_downtime)
                                <code class="font-mono text-xs text-ember-600 dark:text-ember-400">$CREATE_RELEASE()</code> {{ __('clones a new release and links shared storage and .env,') }}
                                <code class="font-mono text-xs text-ember-600 dark:text-ember-400">$ACTIVATE_RELEASE()</code> {{ __('switches traffic to it and reloads PHP-FPM, and') }}
                            @else
                                <code class="font-mono text-xs text-ember-600 dark:text-ember-400">$CREATE_RELEASE()</code> {{ __('pulls the latest commit into the live directory,') }}
                                <code class="font-mono text-xs text-ember-600 dark:text-ember-400">$ACTIVATE_RELEASE()</code> {{ $site->octane ? __('gracefully reloads the Octane workers, and') : __('reloads PHP-FPM, and') }}
                            @endif
                            <code class="font-mono text-xs text-ember-600 dark:text-ember-400">$RESTART_QUEUES()</code> {{ __('restarts the site\'s queue workers.') }}
                        </flux:text>

                        <form wire:submit="saveDeployScript">
                            <flux:textarea wire:model="deploy_script" rows="16" spellcheck="false" class="!bg-zinc-950 font-mono text-xs !text-zinc-200 dark:!bg-black/40" />
                        </form>
                    </div>
                </x-panel>

                @can('update', $site)
                    <x-panel icon="link" :heading="__('Deployment trigger URL')" :description="__('Send a POST request to this URL to deploy from CI or anywhere else.')">
                        <flux:input readonly copyable :value="$site->deployTriggerUrl()" class="font-mono" />
                    </x-panel>
                @endcan
            @endif
        </div>

        <flux:modal name="deployment-output" class="w-full max-w-4xl">
            @if ($deployment = $this->viewingDeployment)
                <div class="flex flex-col gap-4" @if ($deployment->status->isInProgress()) wire:poll.2s @endif>
                    <div class="flex items-center gap-3">
                        <flux:heading size="lg">{{ __('Deployment #:id', ['id' => $deployment->id]) }}</flux:heading>
                        <x-status :color="$deployment->status->color()" :label="$deployment->status->label()" />
                    </div>
                    <x-terminal :title="$site->domain.($deployment->commit_hash ? ' @ '.$deployment->shortHash() : '')">{{ $deployment->output ?: __('Waiting for output...') }}</x-terminal>
                </div>
            @endif
        </flux:modal>
    </x-pages::sites.layout>
</div>
