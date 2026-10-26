<?php

namespace App\Livewire\Concerns;

use App\Jobs\InstallScheduledJob;
use App\Models\Server;
use App\Models\Site;
use App\Ssh\LogFile;
use App\Ssh\LogFiles;
use App\Ssh\RemoteShell;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Throwable;

/**
 * Shows the end of one of a server's logs, read live over SSH and never stored.
 *
 * @property-read Server $server
 */
trait ViewsLogs
{
    /**
     * How many lines a log can be shown with.
     *
     * @var list<int>
     */
    public const array LINE_COUNTS = [100, 500, 1000];

    /**
     * How often a followed log is read again, in seconds. Every read opens an SSH connection to the server.
     */
    public const int FOLLOW_INTERVAL_SECONDS = 10;

    /**
     * How long a log is followed before following stops by itself, so a forgotten tab does not keep reaching the server.
     */
    public const int FOLLOW_MINUTES = 10;

    #[Url]
    public string $log = '';

    public int $lines = 100;

    public string $filter = '';

    public bool $follow = false;

    /**
     * When following started, as a Unix timestamp.
     */
    #[Locked]
    public ?int $followingSince = null;

    /**
     * The end of the selected log, false when it does not exist yet, or null until it has been read.
     */
    public string|false|null $output = null;

    /**
     * The file the output was read from, which for daily logs is the newest of them.
     */
    public ?string $source = null;

    public ?string $readError = null;

    /**
     * @return list<LogFile>
     */
    abstract protected function logFiles(): array;

    /**
     * The server or site whose viewers may read these logs.
     */
    abstract protected function logsBelongTo(): Server|Site;

    /**
     * @return list<LogFile>
     */
    #[Computed]
    public function logs(): array
    {
        return $this->logFiles();
    }

    #[Computed]
    public function selectedLog(): LogFile
    {
        return LogFiles::find($this->logs, $this->log);
    }

    public function updated(string $property): void
    {
        if ($property === 'follow') {
            $this->followingSince = $this->follow ? now()->getTimestamp() : null;
        }

        if (in_array($property, ['log', 'lines', 'filter'], true)) {
            $this->readLog(app(RemoteShell::class));
        }
    }

    public function readLog(RemoteShell $shell): void
    {
        $this->authorize('view', $this->logsBelongTo());

        $this->validate([
            'lines' => ['required', 'integer', Rule::in(self::LINE_COUNTS)],
            'filter' => ['nullable', 'string', 'max:200', 'not_regex:/[\r\n]/'],
        ]);

        if ($this->follow && $this->followingSince < now()->subMinutes(self::FOLLOW_MINUTES)->getTimestamp()) {
            [$this->follow, $this->followingSince] = [false, null];
        }

        $log = $this->selectedLog;
        $this->log = $log->key;
        $this->readError = null;

        try {
            $result = $shell->run($this->server, $log->command($this->lines, $this->filter), as: $log->readAs, timeout: 30);
        } catch (Throwable $exception) {
            $this->readError = $exception->getMessage();

            return;
        }

        if (! str_starts_with($result->output, 'SWAGE_LOG_FOUND')) {
            [$this->output, $this->source] = $result->failed() ? [null, null] : [false, null];
            $this->readError = $result->failed() ? trim($result->output) ?: __('The log could not be read.') : null;

            return;
        }

        [$header, $output] = explode("\n", $result->output, 2) + [1 => ''];

        $this->source = trim(substr($header, strlen('SWAGE_LOG_FOUND')), " '");
        $this->output = $this->server->redactSecrets($output);
    }

    /**
     * Rewrite a scheduled job's cron entry, which starts logging for jobs installed before their output was kept.
     */
    public function reinstallScheduledJob(): void
    {
        $this->authorize('update', $this->server);

        $scheduledJob = $this->server->scheduledJobs()->findOrFail($this->selectedLog->scheduledJobId);

        InstallScheduledJob::dispatch($scheduledJob);

        Flux::toast(variant: 'success', text: __('Reinstalling the scheduled job. Its output will be logged from its next run.'));
    }
}
