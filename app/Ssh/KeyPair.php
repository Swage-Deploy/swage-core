<?php

namespace App\Ssh;

use phpseclib4\Crypt\EC;

final readonly class KeyPair
{
    public function __construct(
        public string $privateKey,
        public string $publicKey,
    ) {}

    /**
     * Generate a new Ed25519 key pair in OpenSSH format.
     */
    public static function generate(string $comment = 'swage'): self
    {
        $key = EC::createKey('Ed25519');

        return new self(
            privateKey: $key->toString('OpenSSH'),
            publicKey: trim($key->getPublicKey()->toString('OpenSSH', ['comment' => $comment])),
        );
    }
}
