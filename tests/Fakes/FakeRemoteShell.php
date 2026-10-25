<?php

namespace Tests\Fakes;

use App\Models\Server;
use App\Ssh\RemoteShell;
use App\Ssh\ScriptResult;
use Closure;
use PHPUnit\Framework\Assert;

class FakeRemoteShell implements RemoteShell
{
    /**
     * @var list<array{server: Server, script: string, as: ?string}>
     */
    public array $ran = [];

    /**
     * @var list<array{server: Server, local: string, remote: string, contents: string}>
     */
    public array $uploaded = [];

    /**
     * @var array<string, ScriptResult>
     */
    protected array $responses = [];

    /**
     * @var array<string, string>
     */
    protected array $streams = [];

    /**
     * Respond to scripts containing the given text with the given result.
     */
    public function respondTo(string $contains, ScriptResult $result): static
    {
        $this->responses[$contains] = $result;

        return $this;
    }

    /**
     * Stream the given standard output from scripts containing the given text.
     */
    public function streamTo(string $contains, string $output): static
    {
        $this->streams[$contains] = $output;

        return $this;
    }

    public function run(Server $server, string $script, ?string $as = null, int $timeout = 600, ?Closure $onOutput = null): ScriptResult
    {
        $this->ran[] = ['server' => $server, 'script' => $script, 'as' => $as];

        $result = collect($this->responses)
            ->first(fn (ScriptResult $result, string $contains): bool => str_contains($script, $contains))
            ?? new ScriptResult(exitCode: 0, output: '');

        if ($onOutput && $result->output !== '') {
            $onOutput($result->output);
        }

        return $result;
    }

    public function stream(Server $server, string $script, Closure $onOutput, int $timeout = 600): ScriptResult
    {
        $this->ran[] = ['server' => $server, 'script' => $script, 'as' => null];

        $output = collect($this->streams)->first(fn (string $output, string $contains): bool => str_contains($script, $contains)) ?? '';

        if ($output !== '') {
            $onOutput($output);
        }

        return collect($this->responses)
            ->first(fn (ScriptResult $result, string $contains): bool => str_contains($script, $contains))
            ?? new ScriptResult(exitCode: 0, output: '');
    }

    public function upload(Server $server, string $localPath, string $remotePath): void
    {
        $this->uploaded[] = ['server' => $server, 'local' => $localPath, 'remote' => $remotePath, 'contents' => file_get_contents($localPath)];
    }

    public function assertRan(string $contains): void
    {
        Assert::assertTrue(
            collect($this->ran)->contains(fn (array $run): bool => str_contains($run['script'], $contains)),
            "No script containing [{$contains}] was run.",
        );
    }

    public function assertNothingRan(): void
    {
        Assert::assertEmpty($this->ran, 'Scripts were run unexpectedly.');
    }

    public function lastScript(): string
    {
        return end($this->ran)['script'] ?? '';
    }
}
