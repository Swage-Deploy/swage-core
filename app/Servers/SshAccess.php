<?php

namespace App\Servers;

/**
 * Who may sign in to a server over SSH, as read from the server.
 */
final readonly class SshAccess
{
    /**
     * The sshd drop-in where Swage lists the users that may not sign in.
     */
    public const string CONFIG = '/etc/ssh/sshd_config.d/01-swage-users.conf';

    /**
     * The sshd drop-in that, while it exists, lets only Swage sign in.
     */
    public const string SWAGE_ONLY_CONFIG = '/etc/ssh/sshd_config.d/01-swage-only.conf';

    /**
     * The only keys sshd accepts while only Swage may sign in: Swage's own.
     */
    public const string SWAGE_ONLY_KEYS = '/etc/ssh/swage/authorized_keys';

    /**
     * @param  array<string, list<AuthorizedKey>>  $keys  Each user's authorized keys.
     * @param  list<string>  $denied  The users sshd refuses, from any of its config.
     * @param  list<string>  $deniedBySwage  The users refused through Swage's drop-in.
     * @param  bool  $swageOnly  Whether sshd accepts only Swage's key, as root, so nobody else can sign in.
     */
    public function __construct(
        public array $keys,
        public array $denied,
        public array $deniedBySwage,
        public bool $swageOnly = false,
    ) {}

    /**
     * Read the output of the ssh.read-access script, whose sections each start with the marker.
     */
    public static function fromOutput(string $output, string $marker): self
    {
        $keys = [];
        $denied = [];
        $deniedBySwage = [];
        $swageOnly = false;
        $section = null;

        foreach (preg_split('/\r?\n/', $output) as $line) {
            if (str_starts_with($line, $marker.' ')) {
                $section = explode(' ', substr($line, strlen($marker) + 1), 2);

                if ($section[0] === 'keys') {
                    $keys[$section[1]] = [];
                }

                continue;
            }

            match ($section[0] ?? null) {
                'keys' => ($key = AuthorizedKey::parse($line)) ? $keys[$section[1]][] = $key : null,
                'denied' => trim($line) !== '' ? $denied[] = trim($line) : null,
                'swage-denied' => trim($line) !== '' ? $deniedBySwage[] = trim($line) : null,
                'keys-file' => $swageOnly = $swageOnly || trim($line) === self::SWAGE_ONLY_KEYS,
                default => null,
            };
        }

        return new self($keys, array_values(array_unique($denied)), array_values(array_unique($deniedBySwage)), $swageOnly);
    }

    /**
     * Restore what toArray() gave, such as from a Livewire component's state.
     *
     * @param  array{keys: array<string, list<array{type: string, data: string, comment: string, options: string}>>, denied: list<string>, deniedBySwage: list<string>, swageOnly: bool}  $access
     */
    public static function fromArray(array $access): self
    {
        return new self(
            array_map(fn (array $keys) => array_map(fn (array $key) => new AuthorizedKey(...$key), $keys), $access['keys']),
            $access['denied'],
            $access['deniedBySwage'],
            $access['swageOnly'],
        );
    }

    /**
     * @return array{keys: array<string, list<array{type: string, data: string, comment: string, options: string}>>, denied: list<string>, deniedBySwage: list<string>, swageOnly: bool}
     */
    public function toArray(): array
    {
        return [
            'keys' => array_map(fn (array $keys) => array_map(fn (AuthorizedKey $key) => (array) $key, $keys), $this->keys),
            'denied' => $this->denied,
            'deniedBySwage' => $this->deniedBySwage,
            'swageOnly' => $this->swageOnly,
        ];
    }

    /**
     * @return list<AuthorizedKey>
     */
    public function keysFor(string $user): array
    {
        return $this->keys[$user] ?? [];
    }

    /**
     * Determine whether sshd lets the user sign in, once SSH is on for more than Swage.
     */
    public function allows(string $user): bool
    {
        return ! in_array($user, $this->denied, true);
    }

    /**
     * Determine whether the user is refused by sshd config Swage didn't write, which Swage can't turn back on.
     */
    public function deniedElsewhere(string $user): bool
    {
        return ! $this->allows($user) && ! in_array($user, $this->deniedBySwage, true);
    }
}
