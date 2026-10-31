<?php

namespace App\Servers;

use App\Models\Database;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A dump being uploaded to restore a database, sent in chunks small enough to pass proxies in front of the panel, such
 * as Cloudflare, which refuses requests over 100 MB. Each upload belongs to the user who started it and the database it
 * is for, and its chunks are appended in order to a file on the local disk.
 */
class DatabaseRestoreUpload
{
    /**
     * The largest dump that can be uploaded.
     */
    public const int MAX_BYTES = 1024 * 1024 * 1024;

    /**
     * The largest chunk accepted in one request.
     */
    public const int CHUNK_BYTES = 32 * 1024 * 1024;

    /**
     * Where uploads in progress are kept, on the local disk.
     */
    public const string DIRECTORY = 'database-restores/uploads';

    /**
     * @param  array{user_id: int, database_id: int, name: string, size: int}  $details
     */
    protected function __construct(public readonly string $id, protected array $details) {}

    /**
     * Start an upload of a file of the given name and size.
     */
    public static function start(User $user, Database $database, string $name, int $size): self
    {
        $upload = new self(Str::random(40), [
            'user_id' => $user->id,
            'database_id' => $database->id,
            'name' => $name,
            'size' => $size,
        ]);

        Storage::disk('local')->put($upload->partPath(), '');
        Storage::disk('local')->put($upload->detailsPath(), json_encode($upload->details));

        return $upload;
    }

    /**
     * Find an upload the user started for the database.
     */
    public static function find(string $id, User $user, Database $database): ?self
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/D', $id)) {
            return null;
        }

        $details = json_decode(Storage::disk('local')->get(self::DIRECTORY."/{$id}.json") ?? '', true);

        if (! is_array($details) || $details['user_id'] !== $user->id || $details['database_id'] !== $database->id) {
            return null;
        }

        return new self($id, $details);
    }

    /**
     * Delete uploads that were started more than a day ago, which were abandoned before they were restored.
     */
    public static function prune(): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->files(self::DIRECTORY) as $file) {
            if ($disk->lastModified($file) < now()->subDay()->getTimestamp()) {
                $disk->delete($file);
            }
        }
    }

    /**
     * The size of the whole file.
     */
    public function size(): int
    {
        return $this->details['size'];
    }

    /**
     * How much of the file has arrived.
     */
    public function received(): int
    {
        return Storage::disk('local')->size($this->partPath());
    }

    /**
     * Determine whether the whole file has arrived.
     */
    public function isComplete(): bool
    {
        return $this->received() === $this->size();
    }

    /**
     * Add the chunk that starts at the given offset, reading at most the given length. Returns false, adding nothing,
     * when the file has arrived up to somewhere else, such as when a chunk is sent again after its response was lost.
     *
     * @param  resource  $chunk
     */
    public function append(int $offset, $chunk, int $length): bool
    {
        $file = fopen(Storage::disk('local')->path($this->partPath()), 'ab');

        try {
            // The lock keeps two requests for the same upload from both appending at the same offset.
            flock($file, LOCK_EX);

            if (fstat($file)['size'] !== $offset) {
                return false;
            }

            stream_copy_to_stream($chunk, $file, $length);

            return true;
        } finally {
            fclose($file);
        }
    }

    /**
     * Hand the finished file over to be restored, returning its path on the local disk. The upload is gone afterwards.
     */
    public function claim(): string
    {
        $path = 'database-restores/'.$this->id.'.dump';

        Storage::disk('local')->move($this->partPath(), $path);
        Storage::disk('local')->delete($this->detailsPath());

        return $path;
    }

    protected function partPath(): string
    {
        return self::DIRECTORY."/{$this->id}.part";
    }

    protected function detailsPath(): string
    {
        return self::DIRECTORY."/{$this->id}.json";
    }
}
