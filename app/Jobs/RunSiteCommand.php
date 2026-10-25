<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Models\SiteCommand;
use App\Sites\ArtisanCommand;
use App\Ssh\RemoteShell;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class RunSiteCommand implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(public SiteCommand $command)
    {
        $this->onQueue(config('swage.deployment_queue'));
    }

    /**
     * Commands wait for the site's deployments, and deployments for them, so a command never runs against a release
     * that is being replaced.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("deploy-site-{$this->command->site_id}"))->releaseAfter(15)->expireAfter(1000)];
    }

    /**
     * Keep waiting behind a deployment for up to an hour.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    /**
     * Run the command as the site's user, streaming its output with the server's secrets masked.
     */
    public function handle(RemoteShell $shell): void
    {
        $site = $this->command->site;
        $server = $site->server;

        $this->command->update(['status' => DeploymentStatus::Running, 'started_at' => now(), 'output' => '']);

        $output = '';
        $flushedAt = microtime(true);
        $secrets = $server->secrets();

        try {
            $result = $shell->run(
                server: $server,
                script: ArtisanCommand::parse($this->command->command)->script($site),
                as: $site->systemUser(),
                timeout: $this->timeout - 60,
                onOutput: function (string $chunk) use (&$output, &$flushedAt, $server, $secrets): void {
                    $output .= $chunk;

                    if (microtime(true) - $flushedAt >= 2) {
                        $this->command->update(['output' => $server->redactSecrets($output, $secrets)]);
                        $flushedAt = microtime(true);
                    }
                },
            );
        } catch (Throwable $exception) {
            $this->finish(DeploymentStatus::Failed, null, $server->redactSecrets($output."\n".$exception->getMessage(), $secrets));

            return;
        }

        $this->finish(
            $result->successful() ? DeploymentStatus::Finished : DeploymentStatus::Failed,
            $result->exitCode,
            $server->redactSecrets($result->timedOut ? $result->output."\n\nThe command timed out." : $result->output, $secrets),
        );
    }

    protected function finish(DeploymentStatus $status, ?int $exitCode, string $output): void
    {
        $this->command->update(['status' => $status, 'exit_code' => $exitCode, 'output' => $output, 'finished_at' => now()]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->command->update([
            'status' => DeploymentStatus::Failed,
            'output' => trim(($this->command->output ?? '')."\n".$exception?->getMessage()),
            'finished_at' => now(),
        ]);
    }
}
