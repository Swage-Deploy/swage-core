<?php

namespace App\Jobs;

use App\Deployments\DeployScript;
use App\Enums\DeploymentStatus;
use App\Enums\ResourceStatus;
use App\Models\Deployment;
use App\Sites\SiteIntegrations;
use App\Ssh\RemoteShell;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class DeploySite implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(public Deployment $deployment)
    {
        $this->onQueue(config('swage.deployment_queue'));
    }

    /**
     * Deployments of the same site run one at a time, in order.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("deploy-site-{$this->deployment->site_id}"))->releaseAfter(15)->expireAfter(1900)];
    }

    /**
     * Keep waiting behind earlier deployments for up to an hour.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    /**
     * Execute the job.
     */
    public function handle(RemoteShell $shell): void
    {
        $site = $this->deployment->site;

        if ($site->status === ResourceStatus::Removing) {
            $this->finish(DeploymentStatus::Failed, 'The site is being removed, so it was not deployed.');

            return;
        }

        $this->deployment->update([
            'status' => DeploymentStatus::Running,
            'started_at' => now(),
            'output' => '',
        ]);

        $output = '';
        $flushedAt = microtime(true);
        $secrets = $site->server->secrets();

        try {
            $result = $shell->run(
                server: $site->server,
                script: DeployScript::render($site),
                as: $site->systemUser(),
                timeout: $this->timeout - 60,
                onOutput: function (string $chunk) use (&$output, &$flushedAt, $site, $secrets): void {
                    $output .= $chunk;

                    if (microtime(true) - $flushedAt >= 2) {
                        $this->deployment->update(['output' => $site->server->redactSecrets($output, $secrets)]);
                        $flushedAt = microtime(true);
                    }
                },
            );
        } catch (Throwable $exception) {
            $this->finish(DeploymentStatus::Failed, $site->server->redactSecrets($output."\n".$exception->getMessage(), $secrets));

            return;
        }

        $this->finish(
            $result->successful() ? DeploymentStatus::Finished : DeploymentStatus::Failed,
            $site->server->redactSecrets($result->timedOut ? $result->output."\n\nThe deployment timed out." : $result->output, $secrets),
        );
    }

    protected function finish(DeploymentStatus $status, string $output): void
    {
        foreach (app(SiteIntegrations::class)->all() as $integration) {
            $integration->deployed($this->deployment->site);
        }

        $this->deployment->update([
            ...DeployScript::commitDetails($output),
            'status' => $status,
            'output' => $output,
            'finished_at' => now(),
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->deployment->update([
            'status' => DeploymentStatus::Failed,
            'output' => trim(($this->deployment->output ?? '')."\n".$exception?->getMessage()),
            'finished_at' => now(),
        ]);
    }
}
