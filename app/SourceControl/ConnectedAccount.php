<?php

namespace App\SourceControl;

use DateTimeInterface;

/**
 * An account a user connected to a provider, with the credentials Swage acts as.
 */
final readonly class ConnectedAccount
{
    public function __construct(
        public string $id,
        public string $username,
        public string $token,
        public ?string $refreshToken = null,
        public ?DateTimeInterface $expiresAt = null,
    ) {}
}
