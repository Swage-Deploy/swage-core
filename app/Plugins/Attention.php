<?php

namespace App\Plugins;

use InvalidArgumentException;

/**
 * Something on a server a plugin wants people to look at, such as open alerts. Swage shows it as a badge on the
 * server's card, its Plugins tab and the plugin's own tab.
 */
final readonly class Attention
{
    public const string HIGH = 'high';

    public const string MEDIUM = 'medium';

    public const string LOW = 'low';

    /**
     * @param  int  $count  How many things need looking at. Zero shows no badge.
     * @param  string  $severity  HIGH, MEDIUM or LOW, for the badge's color: the most severe of the things counted.
     * @param  string  $label  What they are, in a short phrase, such as "3 security findings".
     */
    public function __construct(
        public int $count,
        public string $severity,
        public string $label,
    ) {
        if (! in_array($severity, [self::HIGH, self::MEDIUM, self::LOW], true)) {
            throw new InvalidArgumentException("[{$severity}] is not a severity Swage shows.");
        }
    }

    /**
     * Get the Flux badge color for the severity.
     */
    public function color(): string
    {
        return match ($this->severity) {
            self::HIGH => 'red',
            self::MEDIUM => 'amber',
            default => 'zinc',
        };
    }

    /**
     * Rank severities so the most severe sort first.
     */
    public function rank(): int
    {
        return match ($this->severity) {
            self::HIGH => 3,
            self::MEDIUM => 2,
            default => 1,
        };
    }
}
