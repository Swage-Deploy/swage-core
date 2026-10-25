<?php

namespace App\SourceControl;

/**
 * The repository operations Swage performs as a connected account. Repositories are identified by their full path on
 * the provider, such as "acme/app" or "group/subgroup/app". Every method throws SourceControlException with a message
 * that can be shown to the user when the provider refuses a request.
 */
interface SourceControlClient
{
    /**
     * Get the username of the connected account.
     */
    public function username(): string;

    /**
     * Get the accounts, organizations or groups repositories can be installed from, the user's own account first.
     *
     * @return list<string>
     */
    public function owners(): array;

    /**
     * Get the full paths of an owner's repositories the user may add deploy keys and webhooks to.
     *
     * @return list<string>
     */
    public function repositories(string $owner): array;

    /**
     * Get the default branch of a repository.
     */
    public function defaultBranch(string $repository): string;

    /**
     * Get the SSH URL servers clone a repository from.
     */
    public function cloneUrl(string $repository): string;

    /**
     * Add a read-only deploy key to a repository and return its id.
     */
    public function addDeployKey(string $repository, string $title, string $publicKey): string;

    /**
     * Remove a deploy key from a repository. A key that no longer exists is ignored.
     */
    public function deleteDeployKey(string $repository, string $keyId): void;

    /**
     * Create a webhook that delivers pushes to a URL, signed or authenticated with a secret, and return its id.
     */
    public function createWebhook(string $repository, string $url, string $secret): string;

    /**
     * Remove a webhook from a repository. A webhook that no longer exists is ignored.
     */
    public function deleteWebhook(string $repository, string $webhookId): void;
}
