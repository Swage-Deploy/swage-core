<?php

namespace App\SelfUpdate;

use App\Enums\DeploymentStatus;
use App\Jobs\StartSelfUpdate;
use App\Models\SelfUpdate;
use App\Models\User;
use App\Plugins\PluginInstaller;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Updates the running Swage installation from its git repository.
 *
 * Swage is released through version tags, such as v1.2.0, cut on the tracked branch: an update installs the newest
 * one, or the newest commit on the branch while the repository has no version tags yet. The host migration runner only
 * accepts commits on the branch, and never one older than the host last ran, so a tag elsewhere is never installed.
 * Once the release signers file lists a key, only version tags signed with one are installed.
 *
 * Installs created by the installer use this layout:
 *
 *   {root}/releases/{timestamp}  each release, a shallow git clone
 *   {root}/shared/.env           linked into every release
 *   {root}/shared/storage        linked into every release
 *   {root}/current               symlink to the active release
 *
 * Changes to the server itself ship as host migrations, which the root-owned
 * swage-host-migrate runner applies before a release is activated.
 */
class SelfUpdater
{
    /**
     * @param  string|null  $releasePath  The release directory Swage is running from. Defaults to the application's base path.
     */
    public function __construct(protected ?string $releasePath = null) {}

    /**
     * Determine if this installation uses the release layout the updater manages.
     */
    public function isSupported(): bool
    {
        return basename(dirname($this->releasePath())) === 'releases'
            && is_dir($this->root().'/shared')
            && is_link($this->root().'/current');
    }

    /**
     * The directory holding releases, shared files and the current symlink.
     */
    public function root(): string
    {
        return dirname($this->releasePath(), 2);
    }

    /**
     * The commit the running release was built from.
     */
    public function currentCommit(): ?string
    {
        $result = Process::path($this->releasePath())->run(['git', 'rev-parse', 'HEAD']);

        return $result->successful() ? trim($result->output()) : null;
    }

    /**
     * The version tag the running release was built from, or null when it was built from a branch.
     */
    public function currentVersion(): ?string
    {
        $result = Process::path($this->releasePath())->run(['git', 'describe', '--tags', '--exact-match', 'HEAD']);
        $tag = trim($result->output());

        return $result->successful() && GitRefs::isVersion($tag) ? $tag : null;
    }

    /**
     * The version the running release is, asking the repository when the release was cloned before its commit was
     * tagged.
     */
    public function runningVersion(): ?string
    {
        return $this->currentVersion() ?? $this->versionAt($this->currentCommit());
    }

    /**
     * The newest version tag in the repository pointing to a commit, such as a release cloned from the branch before the
     * commit was tagged. Null when no version tag points to it or the repository cannot be reached.
     */
    public function versionAt(?string $commit): ?string
    {
        $tags = array_filter($this->remoteRefs()['tags'] ?? [], fn (string $tagged): bool => $tagged === $commit);

        return $commit ? GitRefs::newestVersion($tags) : null;
    }

    /**
     * What an update installs: the newest version tag, or the newest commit on the tracked branch while the repository
     * has no version tags. Null when the repository cannot be reached.
     *
     * @return array{ref: string, commit: string, version: ?string}|null
     */
    public function latest(): ?array
    {
        $refs = $this->remoteRefs();

        if ($refs === null) {
            return null;
        }

        if ($version = GitRefs::newestVersion($refs['tags'])) {
            return ['ref' => $version, 'commit' => $refs['tags'][$version], 'version' => $version];
        }

        $commit = $refs['branches'][$this->branch()] ?? null;

        return $commit ? ['ref' => $this->branch(), 'commit' => $commit, 'version' => null] : null;
    }

    /**
     * The repository's branches and tags, or null when it cannot be reached.
     *
     * @return array{branches: array<string, string>, tags: array<string, string>, head: ?string}|null
     */
    protected function remoteRefs(): ?array
    {
        $result = Process::path($this->releasePath())->timeout(30)->run(['git', 'ls-remote', 'origin']);

        return $result->successful() ? GitRefs::parse($result->output()) : null;
    }

    /**
     * Determine if the latest release is one this install should move to: a different commit, and never an older version
     * than the one running.
     *
     * @param  array{ref: string, commit: string, version: ?string}  $latest
     */
    public function isNewer(array $latest, ?string $currentCommit, ?string $currentVersion): bool
    {
        if ($latest['commit'] === $currentCommit) {
            return false;
        }

        return $latest['version'] === null || $currentVersion === null || GitRefs::isNewer($latest['version'], $currentVersion);
    }

    public function branch(): string
    {
        return config('swage.self_update.branch');
    }

    /**
     * Determine if an update is currently queued or running.
     */
    public function isUpdating(): bool
    {
        return SelfUpdate::whereIn('status', [DeploymentStatus::Pending, DeploymentStatus::Running])->exists();
    }

    /**
     * Record an update requested from the dashboard and hand it to the queue worker to start. A plugin operation
     * installs or removes a plugin and rebuilds Swage with the change instead.
     *
     * @param  array{action: 'install', name: string}|array{action: 'install', url: string, ref?: string, commit?: string}|array{action: 'update', names: list<string>}|array{action: 'remove', name: string}|null  $pluginOperation
     */
    public function launch(?User $user = null, ?array $pluginOperation = null): SelfUpdate
    {
        $update = SelfUpdate::create([
            'user_id' => $user?->id,
            'status' => DeploymentStatus::Pending,
            'from_commit' => $this->currentCommit(),
            'from_version' => $this->runningVersion(),
            'plugin_operation' => $pluginOperation,
        ]);

        StartSelfUpdate::dispatch($update);

        return $update;
    }

    /**
     * Run an update in a detached process so it outlives the worker that started it
     * and the restarts that activate the new release.
     */
    public function spawn(SelfUpdate $update): void
    {
        $operation = $update->plugin_operation;

        $arguments = match ($operation['action'] ?? null) {
            'install' => ['swage:plugin:install', ...(isset($operation['url']) ? array_filter([
                '--url='.$operation['url'],
                isset($operation['ref']) ? '--ref='.$operation['ref'] : null,
                isset($operation['commit']) ? '--commit='.$operation['commit'] : null,
            ]) : [$operation['name']]), '--force'],
            'update' => ['swage:plugin:update', ...$operation['names'], '--force'],
            'remove' => ['swage:plugin:remove', $operation['name'], '--force'],
            default => ['swage:update'],
        };

        $command = collect([
            config('swage.self_update.php_binary'),
            $this->releasePath().'/artisan',
            ...$arguments,
            "--update={$update->id}",
        ])->map(fn (string $part): string => escapeshellarg($part))->implode(' ');

        Process::path($this->releasePath())->env($this->environment())->run("setsid nohup {$command} > /dev/null 2>&1 &");
    }

    /**
     * Build, verify and activate a new release, rolling back on failure.
     *
     * @param  (Closure(string): void)|null  $onOutput
     */
    public function run(SelfUpdate $update, bool $force = false, ?Closure $onOutput = null): SelfUpdate
    {
        $lock = Cache::lock('swage-self-update', 3600);

        if (! $lock->get()) {
            return $this->finish($update, DeploymentStatus::Failed, "Another update is already running.\n", $onOutput);
        }

        $update->update(['status' => DeploymentStatus::Running, 'started_at' => now()]);

        try {
            return $this->build($update, $force, $onOutput);
        } catch (Throwable $exception) {
            return $this->finish($update, DeploymentStatus::Failed, "\n".$exception->getMessage()."\n", $onOutput);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  (Closure(string): void)|null  $onOutput
     */
    protected function build(SelfUpdate $update, bool $force, ?Closure $onOutput): SelfUpdate
    {
        if (! $this->isSupported()) {
            throw new RuntimeException('Self-update only works on installs created by the Swage installer.');
        }

        if (! is_file(config('swage.self_update.host_migrator'))) {
            throw new RuntimeException(
                'This install predates host migrations. Run resources/scripts/host/adopt-swage.sh '
                .'as root on this server once, then update again.'
            );
        }

        $target = $this->latest() ?? throw new RuntimeException("Unable to read the version tags or the [{$this->branch()}] branch from the repository.");
        $currentVersion = $this->currentVersion();
        $signature = app(ReleaseSignature::class);

        if ($signature->isRequired() && $target['version'] === null) {
            throw new RuntimeException('Swage only updates to version tags signed with a Swage release key, and the repository has no version tags yet.');
        }

        $update->update(['to_commit' => $target['commit'], 'to_version' => $target['version']]);

        if (! $force && ! $this->isNewer($target, $update->from_commit, $currentVersion)) {
            return $this->finish($update, DeploymentStatus::Finished, 'Already up to date at '.($currentVersion ?? $update->from_commit).".\n", $onOutput);
        }

        $previous = $this->releasePath();
        $release = $this->root().'/releases/'.now()->format('YmdHis');
        $repository = trim(Process::path($previous)->run(['git', 'remote', 'get-url', 'origin'])->throw()->output());
        $php = config('swage.self_update.php_binary');

        try {
            $this->step($update, "Cloning {$target['ref']} into {$release}", ['git', '-c', 'advice.detachedHead=false', 'clone', '--depth', '1', '--branch', $target['ref'], $repository, $release], $this->root(), $onOutput);
            $this->linkSharedFiles($release);

            $commit = $this->commitOf($release);
            $update->update(['to_commit' => $commit]);

            if ($signature->isRequired()) {
                $this->checkSignature($update, $signature, $release, $target['version'], $commit, $onOutput);
            }

            $this->step($update, 'Installing Composer dependencies', ['composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--optimize-autoloader'], $release, $onOutput);

            foreach (app(PluginInstaller::class)->buildSteps() as $step) {
                $this->step($update, $step['label'], $step['command'], $release, $onOutput);
            }

            $this->step($update, 'Installing npm dependencies', ['npm', 'ci'], $release, $onOutput);
            $this->step($update, 'Building assets', ['npm', 'run', 'build'], $release, $onOutput);
            $this->step($update, 'Applying host migrations', ['sudo', '-n', config('swage.self_update.host_migrator'), $commit], $release, $onOutput);
            // Caching boots the release and compiles its config, routes and views without touching the database, so
            // a broken release stops here, before migrations change the schema the running release depends on.
            $this->step($update, 'Caching configuration', [$php, 'artisan', 'optimize'], $release, $onOutput);
            $this->step($update, 'Running migrations', [$php, 'artisan', 'migrate', '--force'], $release, $onOutput);
        } catch (Throwable $exception) {
            File::deleteDirectory($release);

            throw $exception;
        }

        try {
            $this->activate($update, $release, $onOutput);
            $healthy = $this->healthy();
        } catch (Throwable $exception) {
            $this->rollBack($update, $previous, $release, $onOutput);

            throw $exception;
        }

        if (! $healthy) {
            $this->rollBack($update, $previous, $release, $onOutput);

            return $this->finish($update, DeploymentStatus::Failed, "\nThe new release failed its health check. Rolled back to {$update->from_commit}. Database migrations the new release ran stay applied.\n", $onOutput);
        }

        $this->step($update, 'Restarting queue workers', [$php, 'artisan', 'queue:restart'], $release, $onOutput);
        $this->pruneReleases($release);

        return $this->finish($update, DeploymentStatus::Finished, "\nSwage is now running ".($update->to_version ? "{$update->to_version} ({$update->to_commit})" : $update->to_commit).".\n", $onOutput);
    }

    /**
     * Check that the cloned version tag is signed with a release key and points to the commit the release was cloned at.
     *
     * @param  (Closure(string): void)|null  $onOutput
     *
     * @throws RuntimeException
     */
    protected function checkSignature(SelfUpdate $update, ReleaseSignature $signature, string $release, string $version, string $commit, ?Closure $onOutput): void
    {
        $this->write($update, "\n==> Checking the signature of {$version}\n", $onOutput);

        if ($signature->verifyTag($release, $version, $this->environment()) !== $commit) {
            throw new RuntimeException("The signed tag {$version} does not point to the commit that was cloned, so it was not installed.");
        }

        $this->write($update, "Signed with a Swage release key.\n", $onOutput);
    }

    /**
     * Run one step of the update, failing the update if it fails.
     *
     * @param  list<string>  $command
     * @param  (Closure(string): void)|null  $onOutput
     */
    protected function step(SelfUpdate $update, string $label, array $command, string $path, ?Closure $onOutput): void
    {
        $this->write($update, "\n==> {$label}\n", $onOutput);

        $result = Process::path($path)->timeout(900)->env($this->environment())->run($command);

        $this->write($update, $result->output().$result->errorOutput(), $onOutput);

        if ($result->failed()) {
            throw new RuntimeException("{$label} failed with exit code {$result->exitCode()}.");
        }
    }

    /**
     * The commit a freshly cloned release is on, which may be newer than the
     * commit seen before cloning if something was pushed in between.
     */
    protected function commitOf(string $release): string
    {
        return trim(Process::path($release)->run(['git', 'rev-parse', 'HEAD'])->throw()->output());
    }

    protected function linkSharedFiles(string $release): void
    {
        File::deleteDirectory($release.'/storage');
        File::link($this->root().'/shared/storage', $release.'/storage');
        File::link($this->root().'/shared/.env', $release.'/.env');
    }

    /**
     * Atomically point the current symlink at a release and reload PHP-FPM.
     *
     * @param  (Closure(string): void)|null  $onOutput
     */
    protected function activate(SelfUpdate $update, string $release, ?Closure $onOutput): void
    {
        $current = $this->root().'/current';

        $this->write($update, "\n==> Activating ".basename($release)."\n", $onOutput);

        // rename() replaces the symlink atomically, so no request sees a missing release.
        File::delete($current.'-next');
        symlink($release, $current.'-next');
        rename($current.'-next', $current);

        $this->step($update, 'Reloading PHP-FPM', ['sudo', '-n', 'service', config('swage.self_update.php_fpm_service'), 'reload'], $this->root(), $onOutput);
    }

    /**
     * Point the current symlink back at the previous release and delete the new one.
     *
     * The symlink is switched back before PHP-FPM is reloaded, so even when the reload fails again, the previous
     * release is the one PHP-FPM serves once it next reloads.
     *
     * @param  (Closure(string): void)|null  $onOutput
     */
    protected function rollBack(SelfUpdate $update, string $previous, string $release, ?Closure $onOutput): void
    {
        rescue(fn () => $this->activate($update, $previous, $onOutput), report: false);

        File::deleteDirectory($release);
    }

    /**
     * PHP-FPM clears the environment, so commands started from a web request
     * are given the PATH and HOME a login shell would have.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        $home = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? null) : null;

        return array_filter([
            'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'HOME' => getenv('HOME') ?: $home,
            'COMPOSER_NO_INTERACTION' => '1',
        ]);
    }

    /**
     * Confirm the newly activated release can boot and serve requests.
     */
    protected function healthy(): bool
    {
        $url = config('swage.self_update.health_check_url') ?: rtrim(config('app.url'), '/').'/up';

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if (rescue(fn () => Http::timeout(10)->get($url)->successful(), false, report: false)) {
                return true;
            }

            Sleep::for(2)->seconds();
        }

        return false;
    }

    protected function pruneReleases(string $active): void
    {
        collect(File::directories($this->root().'/releases'))
            ->reject(fn (string $release): bool => $release === $active)
            ->sortDesc()
            ->slice(config('swage.self_update.releases_to_keep') - 1)
            ->each(fn (string $release) => File::deleteDirectory($release));
    }

    /**
     * @param  (Closure(string): void)|null  $onOutput
     */
    protected function write(SelfUpdate $update, string $text, ?Closure $onOutput): void
    {
        $update->log($text);

        if ($onOutput) {
            $onOutput($text);
        }
    }

    /**
     * @param  (Closure(string): void)|null  $onOutput
     */
    protected function finish(SelfUpdate $update, DeploymentStatus $status, string $message, ?Closure $onOutput): SelfUpdate
    {
        $this->write($update, $message, $onOutput);

        $update->update(['status' => $status, 'finished_at' => now()]);

        return $update;
    }

    /**
     * The release directory this code is running from.
     */
    protected function releasePath(): string
    {
        $path = $this->releasePath ?? base_path();

        return realpath($path) ?: $path;
    }
}
