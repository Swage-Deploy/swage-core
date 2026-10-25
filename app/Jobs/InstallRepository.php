<?php

namespace App\Jobs;

use App\Actions\Servers\RunServerTask;
use App\Enums\ResourceStatus;
use App\Exceptions\ScriptFailedException;
use App\Models\Site;
use App\SourceControl\SourceControlException;
use App\SourceControl\SourceControlProviders;
use App\Ssh\KeyPair;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class InstallRepository implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public Site $site) {}

    /**
     * Give the server read access to the repository with a dedicated deploy key.
     *
     * A provider adds the key to the repository itself. A custom repository's key is shown to the user to add, so a
     * reinstall keeps the key they already added.
     */
    public function handle(RunServerTask $runTask, SourceControlProviders $providers): void
    {
        $this->site->update(['status' => ResourceStatus::Installing, 'install_error' => null]);

        if ($this->site->usesCustomRepository()) {
            if (blank($this->site->deploy_key_public)) {
                $this->site->update($this->newDeployKey());
            }

            $this->site->update(['clone_url' => $this->site->repository]);
        } else {
            $client = $providers->clientFor($this->site->server->user, $this->site->source_control);

            if ($this->site->deploy_key_id) {
                $client->deleteDeployKey($this->site->repository, $this->site->deploy_key_id);
            }

            $keys = $this->newDeployKey();

            $this->site->update([
                ...$keys,
                'clone_url' => $client->cloneUrl($this->site->repository),
                'deploy_key_id' => $client->addDeployKey(
                    $this->site->repository,
                    "Swage: {$this->site->domain} on {$this->site->server->name}",
                    $keys['deploy_key_public'],
                ),
            ]);
        }

        $runTask->run($this->site->server, "Installing deploy key for {$this->site->repository}", Script::render('sites.install-deploy-key', [
            'site' => $this->site,
        ]));

        $this->site->update(['status' => ResourceStatus::Installed]);
    }

    /**
     * @return array{deploy_key_private: string, deploy_key_public: string}
     */
    protected function newDeployKey(): array
    {
        $keys = KeyPair::generate("swage-site-{$this->site->id}");

        return ['deploy_key_private' => $keys->privateKey, 'deploy_key_public' => $keys->publicKey];
    }

    /**
     * Handle a job failure, keeping why it failed to show on the site's page. A provider's own errors are written for
     * people to read; anything else is only described, since it may hold details meant for the log.
     */
    public function failed(?Throwable $exception): void
    {
        $this->site->update([
            'status' => ResourceStatus::Failed,
            'install_error' => match (true) {
                $exception instanceof SourceControlException => $exception->getMessage(),
                $exception instanceof ScriptFailedException => __('The deploy key could not be installed on the server. Check the server\'s activity log.'),
                default => __('The repository could not be installed. Check the application log for details.'),
            },
        ]);
    }
}
