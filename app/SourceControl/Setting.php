<?php

namespace App\SourceControl;

/**
 * A setting a provider needs, such as its OAuth client ID, which administrators enter on the provider's config page.
 */
final readonly class Setting
{
    public const string TEXT = 'text';

    public const string SECRET = 'secret';

    public const string URL = 'url';

    /**
     * @param  string  $key  Lowercase letters, numbers and underscores, unique within the provider.
     * @param  string  $type  TEXT, URL, or SECRET for values that are stored encrypted and never shown again.
     * @param  bool  $required  Whether the provider cannot be used until the setting has a value.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description = '',
        public string $type = self::TEXT,
        public bool $required = true,
        public string $placeholder = '',
    ) {}

    public function isSecret(): bool
    {
        return $this->type === self::SECRET;
    }
}
