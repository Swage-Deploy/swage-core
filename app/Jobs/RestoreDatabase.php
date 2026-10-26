<?php

namespace App\Jobs;

use App\Actions\Servers\RunServerTask;
use App\Enums\ResourceStatus;
use App\Models\Database;
use App\Ssh\RemoteShell;
use App\Ssh\Script;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class RestoreDatabase implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    /**
     * @param  string  $path  The uploaded file, on the local disk.
     */
    public function __construct(public Database $database, public string $path) {}

    /**
     * Execute the job.
     */
    public function handle(RemoteShell $shell, RunServerTask $runTask): void
    {
        $this->database->update(['status' => ResourceStatus::Restoring]);

        $compressed = $this->isCompressed();

        // Root's home is closed to every other account on the server, so the dump cannot be read while it waits there.
        $remotePath = '/root/swage-restore-'.Str::random(16).($compressed ? '.sql.gz' : '.sql');

        $shell->upload($this->database->server, Storage::disk('local')->path($this->path), $remotePath);

        $runTask->run($this->database->server, "Restoring database {$this->database->name}", Script::render('databases.restore', [
            'database' => $this->database,
            'path' => $remotePath,
            'compressed' => $compressed,
            'ownerPassword' => Str::password(40, symbols: false),
        ]), timeout: $this->timeout - 60);

        $this->database->update(['status' => ResourceStatus::Installed]);

        Storage::disk('local')->delete($this->path);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->database->update(['status' => ResourceStatus::Failed]);

        Storage::disk('local')->delete($this->path);
    }

    /**
     * Determine if the file is gzipped, from its first bytes rather than its name.
     */
    protected function isCompressed(): bool
    {
        $file = Storage::disk('local')->readStream($this->path);
        $header = fread($file, 2);
        fclose($file);

        return $header === "\x1f\x8b";
    }
}
