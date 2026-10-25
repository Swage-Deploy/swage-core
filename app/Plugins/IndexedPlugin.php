<?php

namespace App\Plugins;

/**
 * A plugin listed in the plugin index, and so available to install.
 */
final readonly class IndexedPlugin
{
    use LinksToRepository;

    public function __construct(
        public string $name,
        public string $title,
        public string $description,
        public PluginKind $kind,
        public string $repository,
        public ?string $homepage = null,
    ) {}
}
