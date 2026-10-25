<?php

use App\Actions\Servers\CreateServer;
use App\Enums\PhpVersion;
use App\Enums\ServerType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\IpUtils;

new #[Title('New server')] class extends Component {
    public string $name = '';

    public string $type = 'app';

    public string $ip_address = '';

    public string $private_ip_address = '';

    public int $ssh_port = 22;

    /** @var list<string> */
    public array $php_versions = [];

    public string $default_php_version = '';

    public string $database = 'mysql';

    /**
     * The databases a server can be provisioned with. The other engine, or pgvector, can be installed later.
     *
     * @return array<string, string>
     */
    public function databaseOptions(): array
    {
        return [
            'mysql' => 'MySQL 8',
            'postgres' => 'PostgreSQL 16',
            'postgres-pgvector' => 'PostgreSQL 16 + pgvector',
            // A database server exists to run a database.
            ...($this->type === ServerType::Database->value ? [] : ['none' => __('None')]),
        ];
    }

    /**
     * A database server always gets a database, so None falls back to MySQL when switching to one.
     */
    public function updatedType(): void
    {
        if (! array_key_exists($this->database, $this->databaseOptions())) {
            $this->database = 'mysql';
        }
    }

    public bool $has_redis = true;

    /**
     * Ranges PHP considers public that still reach shared or translated networks: carrier-grade NAT (which Tailscale
     * also uses), IETF protocol assignments, benchmarking, NAT64, 6to4 and Teredo. Each can lead back to the network
     * Swage runs in.
     *
     * @var list<string>
     */
    protected const array NON_PUBLIC_RANGES = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '198.18.0.0/15',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '2002::/16',
        '2001::/32',
    ];

    /**
     * Determine if an address is on the public internet, and not in a private, shared, reserved or tunnelled range
     * that could reach the network Swage runs in.
     */
    protected function isPublicAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && ! IpUtils::checkIp($address, self::NON_PUBLIC_RANGES);
    }

    public function mount(): void
    {
        $this->default_php_version = PhpVersion::latest()->value;
        $this->php_versions = [PhpVersion::latest()->value];
    }

    /**
     * Register the server and show its provisioning command.
     */
    public function save(CreateServer $createServer): void
    {
        $isAppServer = $this->type === ServerType::App->value;

        $validated = $this->validate([
            'type' => ['required', Rule::enum(ServerType::class)],
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/D'],
            // Only administrators may point Swage at its own host or the private network it runs in.
            'ip_address' => ['required', 'ip', fn (string $attribute, mixed $value, Closure $fail) => Gate::denies('administer')
                && ! $this->isPublicAddress((string) $value)
                    ? $fail(__('Enter the server\'s public IP address.'))
                    : null,
            ],
            'private_ip_address' => ['nullable', 'ip', 'different:ip_address'],
            'ssh_port' => ['required', 'integer', 'between:1,65535'],
            'php_versions' => $isAppServer ? ['required', 'array', 'min:1'] : ['exclude'],
            'php_versions.*' => $isAppServer ? [Rule::enum(PhpVersion::class)] : ['exclude'],
            'default_php_version' => $isAppServer ? ['required', Rule::in($this->php_versions)] : ['exclude'],
            'database' => ['required', Rule::in(array_keys($this->databaseOptions()))],
            'has_redis' => $isAppServer ? ['boolean'] : ['exclude'],
        ], [
            'name.regex' => __('The name may only contain letters, numbers, dots, dashes and underscores.'),
            'default_php_version.in' => __('The default PHP version must be one of the installed versions.'),
        ]);

        $database = Arr::pull($validated, 'database');

        $server = $createServer->handle(Auth::user(), [
            'php_versions' => [],
            'default_php_version' => null,
            'has_redis' => false,
            ...$validated,
            'private_ip_address' => $validated['private_ip_address'] ?: null,
            'has_mysql' => $database === 'mysql',
            'has_postgres' => str_starts_with($database, 'postgres'),
            'has_pgvector' => $database === 'postgres-pgvector',
        ]);

        $this->redirectRoute('servers.show', $server, navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:breadcrumbs class="mb-2">
            <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>{{ __('Servers') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('New') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
        <flux:heading size="xl" level="1" class="tracking-tight">{{ __('Connect a server') }}</flux:heading>
        <flux:subheading class="max-w-2xl">{{ __('Create a fresh Ubuntu 24.04 server with any provider, then fill in its details. Swage detects its CPU, memory and disk during provisioning and tunes every service to match.') }}</flux:subheading>
    </div>

    <div class="grid items-start gap-6 lg:grid-cols-[1fr_320px]">
    <x-panel icon="server" :heading="__('Server details')">
    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:radio.group wire:model.live="type" :label="__('Type')" variant="cards" class="max-sm:flex-col">
            @foreach (ServerType::cases() as $serverType)
                <flux:radio :value="$serverType->value" :label="$serverType->label()" :description="$serverType->description()" />
            @endforeach
        </flux:radio.group>

        <flux:input wire:model="name" :label="__('Name')" placeholder="production-1" required autofocus />

        <div class="grid gap-6 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <flux:input wire:model="ip_address" :label="__('Public IP address')" placeholder="203.0.113.10" required />
            </div>
            <flux:input wire:model="ssh_port" type="number" :label="__('SSH port')" required />
        </div>

        <flux:input wire:model="private_ip_address" :label="__('Private IP address')" :badge="__('Optional')" placeholder="10.0.0.5" :description="$type === 'database'
            ? __('From your provider\'s private network. The databases listen on it instead of the public address.')
            : __('From your provider\'s private network, for connecting to database servers.')" />

        @if ($type === 'app')
        <flux:checkbox.group wire:model.live="php_versions" :label="__('PHP versions')" :description="__('Every selected version is installed with its own tuned FPM pool. Sites choose which version they run.')">
            @foreach (App\Enums\PhpVersion::cases() as $version)
                <flux:checkbox :value="$version->value" :label="'PHP '.$version->value" />
            @endforeach
        </flux:checkbox.group>

        <flux:select wire:model="default_php_version" :label="__('Default PHP version')" :description="__('The CLI default. Its FPM pool gets the largest share of memory.')">
            @foreach ($php_versions as $version)
                <flux:select.option :value="$version" wire:key="php-{{ $version }}">PHP {{ $version }}</flux:select.option>
            @endforeach
        </flux:select>

        @endif

        <flux:select wire:model="database" :label="__('Database')" :description="__('The other engine, or pgvector for vector embeddings, can be added from the server page later.')">
            @foreach ($this->databaseOptions() as $value => $label)
                <flux:select.option :value="$value">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($type === 'app')
            <flux:checkbox wire:model="has_redis" :label="__('Install Redis')" :description="__('Used for cache, sessions and queues.')" />
        @endif

        <div>
            <flux:button type="submit" variant="primary" icon="fire">{{ __('Create server') }}</flux:button>
        </div>
    </form>
    </x-panel>

    <aside class="flex flex-col gap-4 rounded-xl border border-ember-500/20 bg-ember-500/[0.04] p-5">
        <flux:heading class="flex items-center gap-2"><flux:icon.sparkles variant="mini" class="text-ember-500" />{{ __('What happens next') }}</flux:heading>
        <ol class="flex flex-col gap-4 text-sm">
            @foreach ([
                [__('Run one command'), __('Swage gives you a curl command to run as root on the new server.')],
                [__('Hardware is detected'), __('CPU cores, memory and disk are reported back to Swage.')],
                [__('Services are tuned'), __('PHP-FPM workers, MySQL or PostgreSQL memory, Redis memory, nginx workers and swap are sized to fit.')],
                [__('Ready for sites'), __('Add sites, install repositories and deploy.')],
            ] as $index => [$title, $text])
                <li class="flex gap-3">
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-ember-500/15 font-mono text-xs font-medium text-ember-600 dark:text-ember-400">{{ $index + 1 }}</span>
                    <div>
                        <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $title }}</div>
                        <div class="text-zinc-500">{{ $text }}</div>
                    </div>
                </li>
            @endforeach
        </ol>
    </aside>
    </div>
</div>
