<?php

namespace App\Plugins;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The list of plugins that can be installed, kept as plugins.json in the plugin index repository. Plugins are only
 * installed and updated from the repositories it lists.
 */
class PluginIndex
{
    /**
     * Get every plugin the index lists. Entries that are not well formed are left out.
     *
     * @return array<string, IndexedPlugin>
     *
     * @throws RuntimeException when the index cannot be fetched.
     */
    public function all(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget($this->cacheKey());
        }

        $entries = Cache::remember($this->cacheKey(), now()->addMinutes(10), fn (): array => $this->fetch());

        return collect($entries)
            ->map(fn (mixed $entry): ?IndexedPlugin => is_array($entry) ? $this->entry($entry) : null)
            ->filter()
            ->keyBy('name')
            ->all();
    }

    /**
     * @throws RuntimeException when the index cannot be fetched.
     */
    public function find(string $name): ?IndexedPlugin
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * @return list<mixed>
     *
     * @throws RuntimeException
     */
    protected function fetch(): array
    {
        try {
            $response = Http::timeout(10)->retry(2, 200, throw: false)->get(config('swage.plugins.index'));
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to reach the plugin index.', previous: $exception);
        }

        $plugins = $response->successful() ? $response->json('plugins') : null;

        if (! is_array($plugins)) {
            throw new RuntimeException('Unable to read the plugin index at '.config('swage.plugins.index').'.');
        }

        return $plugins;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function entry(array $entry): ?IndexedPlugin
    {
        $name = $entry['name'] ?? null;
        $repository = $entry['repository'] ?? null;
        $kind = PluginKind::tryFrom((string) ($entry['kind'] ?? ''));

        if (! is_string($name) || ! preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$/D', $name)
            || ! is_string($repository) || ! preg_match('/^(https:\/\/|git@|ssh:\/\/)\S+$/D', $repository)
            || $kind === null) {
            return null;
        }

        return new IndexedPlugin(
            name: $name,
            title: is_string($entry['title'] ?? null) ? $entry['title'] : $name,
            description: is_string($entry['description'] ?? null) ? $entry['description'] : '',
            kind: $kind,
            repository: $repository,
            homepage: is_string($entry['homepage'] ?? null) && str_starts_with($entry['homepage'], 'https://') ? $entry['homepage'] : null,
        );
    }

    protected function cacheKey(): string
    {
        return 'plugin-index:'.hash('xxh128', (string) config('swage.plugins.index'));
    }
}
