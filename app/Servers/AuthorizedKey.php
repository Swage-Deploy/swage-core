<?php

namespace App\Servers;

/**
 * One key in a user's authorized_keys file on a server.
 */
final readonly class AuthorizedKey
{
    /**
     * Key types sshd on Ubuntu 24.04 accepts.
     *
     * @var list<string>
     */
    public const array TYPES = [
        'ssh-ed25519', 'ssh-rsa', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521',
        'sk-ssh-ed25519@openssh.com', 'sk-ecdsa-sha2-nistp256@openssh.com',
    ];

    public function __construct(
        public string $type,
        public string $data,
        public string $comment = '',
        public string $options = '',
    ) {}

    /**
     * Read a line of an authorized_keys file, or a pasted public key, or null when it holds no valid key. Options in
     * front of the key, such as from= or command=, are kept but not checked.
     */
    public static function parse(string $line): ?self
    {
        $line = trim(preg_replace('/\s+/', ' ', $line));
        $types = implode('|', array_map(fn (string $type) => preg_quote($type, '/'), self::TYPES));

        if ($line === '' || str_starts_with($line, '#') || ! preg_match('/^(.*?)(?:^| )('.$types.') ([A-Za-z0-9+\/]+={0,2})(?: (.*))?$/D', $line, $matches)) {
            return null;
        }

        // The key data starts with its own type, so a key pasted with the wrong type in front of it is caught here.
        $blob = base64_decode($matches[3], true);

        if ($blob === false || strlen($blob) < 4 || substr($blob, 4, unpack('N', $blob)[1]) !== $matches[2]) {
            return null;
        }

        return new self($matches[2], $matches[3], $matches[4] ?? '', $matches[1]);
    }

    /**
     * Get the key's SHA256 fingerprint, as ssh-keygen -l shows it.
     */
    public function fingerprint(): string
    {
        return 'SHA256:'.rtrim(base64_encode(hash('sha256', base64_decode($this->data), true)), '=');
    }

    /**
     * Determine whether this is the same key as the given public key line, whatever the comments.
     */
    public function is(?string $publicKey): bool
    {
        return self::parse((string) $publicKey)?->data === $this->data;
    }
}
