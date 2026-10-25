<?php

namespace Tests\Fakes;

use App\Models\SourceControlConnection;
use App\SourceControl\SourceControlClient;
use App\SourceControl\SourceControlException;

class FakeSourceControlClient implements SourceControlClient
{
    public ?SourceControlConnection $connection = null;

    /**
     * @var list<array{0: string, 1: list<string>}>
     */
    public array $calls = [];

    /**
     * The error adding a deploy key fails with, or null to succeed.
     */
    public ?string $failAddingDeployKey = null;

    public function username(): string
    {
        return 'jane';
    }

    public function owners(): array
    {
        return ['jane', 'team/platform'];
    }

    public function repositories(string $owner): array
    {
        return $owner === 'team/platform' ? ['team/platform/api'] : ['jane/blog'];
    }

    public function defaultBranch(string $repository): string
    {
        return 'trunk';
    }

    public function cloneUrl(string $repository): string
    {
        return "git@git.example.com:{$repository}.git";
    }

    public function addDeployKey(string $repository, string $title, string $publicKey): string
    {
        $this->calls[] = ['addDeployKey', [$repository, $title, $publicKey]];

        if ($this->failAddingDeployKey !== null) {
            throw new SourceControlException($this->failAddingDeployKey);
        }

        return 'key-1';
    }

    public function deleteDeployKey(string $repository, string $keyId): void
    {
        $this->calls[] = ['deleteDeployKey', [$repository, $keyId]];
    }

    public function createWebhook(string $repository, string $url, string $secret): string
    {
        $this->calls[] = ['createWebhook', [$repository, $url, $secret]];

        return 'hook-1';
    }

    public function deleteWebhook(string $repository, string $webhookId): void
    {
        $this->calls[] = ['deleteWebhook', [$repository, $webhookId]];
    }
}
