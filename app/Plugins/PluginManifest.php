<?php

namespace App\Plugins;

use Illuminate\Support\Facades\File;

/**
 * The list of installed plugins, kept as JSON in storage so every release builds the same plugins.
 */
class PluginManifest
{
    /**
     * @return list<Plugin>
     */
    public function all(): array
    {
        $path = config('swage.plugins.manifest');

        if (! File::exists($path)) {
            return [];
        }

        return array_map(Plugin::fromArray(...), json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR)['plugins'] ?? []);
    }

    public function find(string $name): ?Plugin
    {
        return collect($this->all())->firstWhere('name', $name);
    }

    /**
     * Add a plugin, or replace the installed version of it.
     */
    public function save(Plugin $plugin): void
    {
        $this->write([...array_filter($this->all(), fn (Plugin $installed): bool => $installed->name !== $plugin->name), $plugin]);
    }

    public function remove(string $name): void
    {
        $this->write(array_filter($this->all(), fn (Plugin $installed): bool => $installed->name !== $name));
    }

    /**
     * @param  array<Plugin>  $plugins
     */
    protected function write(array $plugins): void
    {
        $path = config('swage.plugins.manifest');

        File::ensureDirectoryExists(dirname($path));

        $plugins = collect($plugins)->sortBy('name')->map->toArray()->values()->all();

        File::put($path, json_encode(['plugins' => $plugins], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
