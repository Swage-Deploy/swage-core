<?php

namespace App\Ssh;

/**
 * A log on a server: a file, the newest file matching a pattern, or a systemd unit's journal.
 */
final readonly class LogFile
{
    /**
     * The most bytes of a log sent back to the browser at once.
     */
    public const int MAX_BYTES = 524288;

    public function __construct(
        public string $key,
        public string $label,
        public string $group,
        public ?string $path = null,
        public ?string $directory = null,
        public ?string $pattern = null,
        public ?string $unit = null,
        public ?int $scheduledJobId = null,
        public ?string $readAs = null,
    ) {}

    /**
     * A log written to one file.
     *
     * A log in a directory a site's user can write to is read as that user, given as $readAs, so a link the user puts in
     * the log's place cannot make root read another file.
     */
    public static function file(string $key, string $label, string $group, string $path, ?int $scheduledJobId = null, ?string $readAs = null): self
    {
        return new self($key, $label, $group, path: $path, scheduledJobId: $scheduledJobId, readAs: $readAs);
    }

    /**
     * A log split across files, such as Laravel's daily logs, of which the most recently written is shown.
     */
    public static function newest(string $key, string $label, string $group, string $directory, string $pattern, ?string $readAs = null): self
    {
        return new self($key, $label, $group, directory: $directory, pattern: $pattern, readAs: $readAs);
    }

    /**
     * A log kept in the systemd journal.
     */
    public static function journal(string $key, string $label, string $group, string $unit): self
    {
        return new self($key, $label, $group, unit: $unit);
    }

    /**
     * Describe where the log lives on the server.
     */
    public function location(): string
    {
        return match (true) {
            $this->unit !== null => "journalctl -u {$this->unit}",
            $this->directory !== null => "{$this->directory}/{$this->pattern}",
            default => (string) $this->path,
        };
    }

    /**
     * Build the script that prints the end of the log, keeping only lines containing the filter when one is given.
     *
     * The first line of output is "SWAGE_LOG_FOUND" and the file read, and is missing when the log does not exist yet.
     */
    public function command(int $lines, ?string $filter = null): string
    {
        $filter = filled($filter) ? ' | grep -iF -- '.escapeshellarg($filter) : '';
        $limit = " | tail -n {$lines} | tail -c ".self::MAX_BYTES;

        if ($this->unit !== null) {
            $unit = escapeshellarg($this->unit);
            $read = $filter === ''
                ? "journalctl -u {$unit} -n {$lines} --no-pager -o short-iso | tail -c ".self::MAX_BYTES
                : "journalctl -u {$unit} --no-pager -o short-iso{$filter}{$limit}";

            return "echo SWAGE_LOG_FOUND {$unit}; {$read}";
        }

        $find = $this->directory !== null
            ? 'f=$(ls -1t -- '.escapeshellarg($this->directory).'/'.$this->pattern.' 2>/dev/null | head -n 1)'
            : 'f='.escapeshellarg((string) $this->path);

        $read = $filter === '' ? 'tail -n '.$lines.' -- "$f" | tail -c '.self::MAX_BYTES : 'cat -- "$f"'.$filter.$limit;

        return $find.'; if [ -n "$f" ] && [ -f "$f" ]; then echo "SWAGE_LOG_FOUND $f"; '.$read.'; fi';
    }
}
