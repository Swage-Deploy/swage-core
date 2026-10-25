<?php

namespace App\Plugins;

use App\SelfUpdate\GitRefs;
use App\SelfUpdate\SelfUpdater;
use App\SourceControl\SourceControlProviders;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fetches plugins from git repositories and builds them into releases.
 *
 * Plugins are released and updated through version tags: a new install gets the newest version tag, or the default
 * branch when the repository has none yet. The commit it was installed at is recorded, so rebuilding Swage never
 * changes a plugin's code: only updating it does.
 *
 * Each version of a plugin is checked out into a directory of its own, named after its commit, so a failed update
 * leaves the previous version's checkout in place for the release that is still running.
 */
class PluginInstaller
{
    public function __construct(protected PluginManifest $manifest) {}

    /**
     * Fetch a plugin and check that it is a Swage plugin written for this version of the contract. The plugin is not
     * added to the manifest.
     *
     * @param  string|null  $ref  A tag or branch to install instead of the newest version.
     *
     * @throws RuntimeException when the repository cannot be fetched or does not hold a compatible plugin.
     */
    public function fetch(string $repository, ?string $ref = null): Plugin
    {
        $this->validateSource($repository, $ref);

        $ref ??= $this->latest($repository)['ref'];

        $staging = $this->directory().'/.fetching-'.Str::random(8);
        File::ensureDirectoryExists($staging);

        try {
            $this->git($staging, ['init', '--quiet']);
            $this->git($staging, ['remote', 'add', 'origin', $repository]);
            $this->git($staging, ['fetch', '--quiet', '--depth', '1', 'origin', $ref], "Unable to fetch {$ref} from {$repository}.");
            $this->git($staging, ['checkout', '--quiet', '--detach', 'FETCH_HEAD']);

            $commit = trim($this->git($staging, ['rev-parse', 'HEAD']));
            [$name, $kind] = $this->readComposer($staging);
            $checkout = Str::slug(str_replace('/', '-', $name)).'-'.substr($commit, 0, 12);

            File::deleteDirectory($this->directory().'/'.$checkout);
            File::moveDirectory($staging, $this->directory().'/'.$checkout);
        } finally {
            File::deleteDirectory($staging);
        }

        return new Plugin(
            name: $name,
            kind: $kind,
            repository: $repository,
            ref: $ref,
            commit: $commit,
            checkout: $checkout,
            installedAt: now()->toIso8601String(),
        );
    }

    /**
     * The commands that add every installed plugin to a release, after its own Composer dependencies are installed.
     * Each checkout is first checked against the commit it was installed at.
     *
     * @return list<array{label: string, command: list<string>}>
     *
     * @throws RuntimeException when a checkout is missing or has changed since it was installed.
     */
    public function buildSteps(): array
    {
        $plugins = $this->manifest->all();

        if ($plugins === []) {
            return [];
        }

        $steps = [];

        foreach ($plugins as $plugin) {
            $this->verify($plugin);

            $steps[] = [
                'label' => "Adding the {$plugin->name} plugin at {$plugin->shortCommit()}",
                'command' => ['composer', 'config', 'repositories.swage-plugin-'.Str::slug(str_replace('/', '-', $plugin->name)), json_encode([
                    'type' => 'path',
                    'url' => $this->packagePath($plugin),
                    'options' => ['symlink' => false],
                ], JSON_UNESCAPED_SLASHES)],
            ];
        }

        // Requiring runs the app's post-update scripts, which call development tools releases do not install, so the
        // scripts are skipped and the autoloader is rebuilt after, which still discovers the plugins' service providers.
        $steps[] = [
            'label' => 'Installing plugins',
            'command' => [
                'composer', 'require', '--no-interaction', '--update-no-dev', '--no-scripts',
                ...array_map(fn (Plugin $plugin): string => "{$plugin->name}:*@dev", $plugins),
            ],
        ];

        $steps[] = [
            'label' => 'Discovering plugins',
            'command' => ['composer', 'dump-autoload', '--no-interaction', '--optimize', '--no-dev'],
        ];

        return $steps;
    }

    /**
     * Check that a plugin's checkout is still at the commit it was installed at and has not been modified.
     *
     * @throws RuntimeException
     */
    public function verify(Plugin $plugin): void
    {
        $directory = $this->directory().'/'.$plugin->checkout;

        if (! File::isDirectory($directory)) {
            throw new RuntimeException("The {$plugin->name} plugin's checkout is missing. Install it again from the Plugins page.");
        }

        $head = trim($this->git($directory, ['rev-parse', 'HEAD']));
        $changes = trim($this->git($directory, ['status', '--porcelain']));

        if ($head !== $plugin->commit || $changes !== '') {
            throw new RuntimeException("The {$plugin->name} plugin's checkout has changed since it was installed at {$plugin->shortCommit()}. Install it again from the Plugins page.");
        }
    }

    /**
     * Delete the checkouts no installed plugin uses, such as a removed plugin's or an upgraded plugin's old version.
     */
    public function pruneCheckouts(): void
    {
        $inUse = array_map(fn (Plugin $plugin): string => $plugin->checkout, $this->manifest->all());

        foreach (File::directories($this->directory()) as $directory) {
            if (! in_array(basename($directory), $inUse, true)) {
                File::deleteDirectory($directory);
            }
        }
    }

    /**
     * The plugins directory, resolved through the storage symlink so paths stay valid after the release that recorded
     * them is pruned.
     */
    public function directory(): string
    {
        $directory = config('swage.plugins.directory');

        File::ensureDirectoryExists($directory);

        return realpath($directory) ?: $directory;
    }

    public function packagePath(Plugin $plugin): string
    {
        return $this->directory().'/'.$plugin->checkout;
    }

    /**
     * Read the package name and kind from a plugin's composer.json, refusing packages that are not plugins for this
     * contract or extend something plugins cannot.
     *
     * @return array{0: string, 1: PluginKind}
     *
     * @throws RuntimeException
     */
    protected function readComposer(string $directory): array
    {
        $composer = json_decode((string) @file_get_contents($directory.'/composer.json'), true);

        if (! is_array($composer)) {
            throw new RuntimeException('The repository has no composer.json at its root.');
        }

        $name = (string) ($composer['name'] ?? '');

        if (! preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$/D', $name)) {
            throw new RuntimeException("[{$name}] is not a valid Composer package name.");
        }

        if (($composer['type'] ?? null) !== 'swage-plugin') {
            throw new RuntimeException("{$name} is not a Swage plugin: its composer.json type must be \"swage-plugin\".");
        }

        $contract = $composer['extra']['swage']['contract'] ?? null;

        if ($contract !== SourceControlProviders::CONTRACT_VERSION) {
            throw new RuntimeException("{$name} is written for version ".var_export($contract, true).' of the plugin contract, but this Swage provides version '.SourceControlProviders::CONTRACT_VERSION.'.');
        }

        $kind = PluginKind::tryFrom((string) ($composer['extra']['swage']['kind'] ?? ''));

        if ($kind === null) {
            throw new RuntimeException("{$name} does not say what it extends: its composer.json needs extra.swage.kind set to one of ".implode(', ', array_column(PluginKind::cases(), 'value')).'.');
        }

        if (empty($composer['extra']['laravel']['providers'])) {
            throw new RuntimeException("{$name} registers no Laravel service provider in extra.laravel.providers, so it would never be loaded.");
        }

        return [$name, $kind];
    }

    /**
     * Find a newer version of a plugin. Plugins are updated through version tags: once a repository has them, its
     * newest one is offered, which also moves a plugin installed from a branch over to tags. Only a repository with no
     * version tags offers new commits on the branch the plugin was installed from.
     *
     * @return array{ref: string, commit: string}|null null when the plugin is up to date.
     *
     * @throws RuntimeException when the repository cannot be reached.
     */
    public function availableUpdate(Plugin $plugin): ?array
    {
        $refs = $this->remoteRefs($plugin->repository);

        if ($latest = GitRefs::newestVersion($refs['tags'])) {
            if ($refs['tags'][$latest] === $plugin->commit) {
                return null;
            }

            return ! GitRefs::isVersion($plugin->ref) || GitRefs::isNewer($latest, $plugin->ref)
                ? ['ref' => $latest, 'commit' => $refs['tags'][$latest]]
                : null;
        }

        if (isset($refs['branches'][$plugin->ref]) && $refs['branches'][$plugin->ref] !== $plugin->commit) {
            return ['ref' => $plugin->ref, 'commit' => $refs['branches'][$plugin->ref]];
        }

        return null;
    }

    /**
     * What a new install follows: the newest version tag, or the default branch when there are no version tags.
     *
     * @return array{ref: string, commit: string}
     *
     * @throws RuntimeException
     */
    public function latest(string $repository): array
    {
        $refs = $this->remoteRefs($repository);

        if ($version = GitRefs::newestVersion($refs['tags'])) {
            return ['ref' => $version, 'commit' => $refs['tags'][$version]];
        }

        if ($refs['head'] === null || ! isset($refs['branches'][$refs['head']])) {
            throw new RuntimeException("{$repository} has no version tags or default branch to install.");
        }

        return ['ref' => $refs['head'], 'commit' => $refs['branches'][$refs['head']]];
    }

    /**
     * Check where a plugin is installed from before anything is fetched.
     *
     * @throws RuntimeException
     */
    public function validateSource(string $repository, ?string $ref = null): void
    {
        if ($repository === '' || str_starts_with($repository, '-') || preg_match('/\s/', $repository)) {
            throw new RuntimeException('Enter the git URL of the plugin\'s repository.');
        }

        if ($ref !== null && (! preg_match('/^[A-Za-z0-9._\/-]+$/D', $ref) || str_starts_with($ref, '-') || str_contains($ref, '..'))) {
            throw new RuntimeException('Give a tag or branch to install.');
        }
    }

    /**
     * The repository's branches and tags, with the commits they point to, and its default branch.
     *
     * @return array{branches: array<string, string>, tags: array<string, string>, head: ?string}
     *
     * @throws RuntimeException
     */
    protected function remoteRefs(string $repository): array
    {
        return GitRefs::parse($this->git($this->directory(), ['ls-remote', '--symref', $repository], "Unable to reach {$repository}."));
    }

    /**
     * @param  list<string>  $arguments
     *
     * @throws RuntimeException
     */
    protected function git(string $directory, array $arguments, ?string $failure = null): string
    {
        $result = Process::path($directory)->env(app(SelfUpdater::class)->environment())->timeout(120)->run(['git', ...$arguments]);

        if ($result->failed()) {
            throw new RuntimeException(trim(($failure ?? 'git '.$arguments[0].' failed.').' '.trim($result->errorOutput())));
        }

        return $result->output();
    }
}
