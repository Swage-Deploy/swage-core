<?php

namespace App\Plugins;

/**
 * A plugin installed from a git repository. It follows a version tag or a branch, and stays at the commit that pointed
 * to when it was installed or last updated.
 *
 * Official plugins come from the plugin index, from the repository it lists, and only from signed version tags once
 * Swage has release keys. Others are installed straight from a repository by an administrator and are not checked.
 */
final readonly class Plugin
{
    use LinksToRepository;

    /**
     * @param  string  $name  The Composer package name, such as "acme/swage-gitea".
     * @param  PluginKind  $kind  What the plugin extends.
     * @param  string  $repository  The git URL the plugin was installed from.
     * @param  string  $ref  The version tag or branch the plugin is at.
     * @param  string  $commit  The full commit the ref pointed to when it was installed.
     * @param  string  $checkout  The checkout's directory name within the plugins directory.
     * @param  bool  $official  Whether the plugin was installed from the plugin index.
     */
    public function __construct(
        public string $name,
        public PluginKind $kind,
        public string $repository,
        public string $ref,
        public string $commit,
        public string $checkout,
        public string $installedAt,
        public bool $official = true,
    ) {}

    /**
     * Plugins recorded before unofficial plugins existed were all installed from the plugin index.
     *
     * @param  array<string, string|bool>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            name: $attributes['name'],
            kind: PluginKind::from($attributes['kind'] ?? PluginKind::SourceControl->value),
            repository: $attributes['repository'],
            ref: $attributes['ref'],
            commit: $attributes['commit'],
            checkout: $attributes['checkout'],
            installedAt: $attributes['installed_at'],
            official: (bool) ($attributes['official'] ?? true),
        );
    }

    /**
     * @return array<string, string|bool>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'kind' => $this->kind->value,
            'repository' => $this->repository,
            'ref' => $this->ref,
            'commit' => $this->commit,
            'checkout' => $this->checkout,
            'installed_at' => $this->installedAt,
            'official' => $this->official,
        ];
    }

    public function shortCommit(): string
    {
        return substr($this->commit, 0, 12);
    }
}
