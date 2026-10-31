<?php

namespace App\Jobs;

use App\Actions\Servers\RunServerTask;
use App\Enums\PhpTask;
use App\Enums\PhpVersion;
use App\Models\Server;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Throwable;

class ChangePhpVersion implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public Server $server, public PhpTask $task, public PhpVersion $php) {}

    /**
     * Install, remove or make default a PHP version, then re-tune the server. Re-tuning writes every installed
     * version's PHP-FPM pool and settings, including any custom PHP settings saved in Swage, and gives the default
     * version's pool the largest share of memory.
     */
    public function handle(RunServerTask $runTask): void
    {
        match ($this->task) {
            PhpTask::Install => $this->install($runTask),
            PhpTask::MakeDefault => $this->makeDefault($runTask),
            PhpTask::Remove => $this->remove($runTask),
        };

        Bus::dispatchSync(new OptimizeServer($this->server));

        $this->server->update(['php_task' => null, 'php_task_version' => null]);
    }

    protected function install(RunServerTask $runTask): void
    {
        $runTask->run($this->server, $this->task->label($this->php), Script::render('php.install-version', [
            'php' => $this->php,
        ]), timeout: 1200);

        $this->server->update([
            'php_versions' => collect($this->server->php_versions)
                ->push($this->php)
                ->unique()
                ->sortBy(fn (PhpVersion $php): string => $php->value, SORT_NATURAL)
                ->values(),
        ]);
    }

    protected function makeDefault(RunServerTask $runTask): void
    {
        $runTask->run($this->server, $this->task->label($this->php), Script::render('php.make-default', [
            'php' => $this->php,
        ]));

        $this->server->update(['default_php_version' => $this->php]);
    }

    protected function remove(RunServerTask $runTask): void
    {
        // A site may have switched to the version after the removal was asked for.
        if ($users = $this->server->phpVersionUsers($this->php)) {
            throw new RuntimeException("PHP {$this->php->value} is still used by ".implode(', ', $users).'.');
        }

        $runTask->run($this->server, $this->task->label($this->php), Script::render('php.remove-version', [
            'php' => $this->php,
        ]), timeout: 1200);

        $this->server->update([
            'php_versions' => $this->server->php_versions->reject(fn (PhpVersion $php): bool => $php === $this->php)->values(),
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->server->update(['php_task' => null, 'php_task_version' => null]);
    }
}
