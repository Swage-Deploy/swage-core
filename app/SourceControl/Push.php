<?php

namespace App\SourceControl;

/**
 * A push to a repository, read from a provider's webhook delivery.
 */
final readonly class Push
{
    public function __construct(
        public string $branch,
        public ?string $pusher = null,
    ) {}
}
