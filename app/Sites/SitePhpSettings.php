<?php

namespace App\Sites;

use App\Models\Site;
use Illuminate\Validation\ValidationException;

/**
 * The PHP settings an isolated site's web requests run with, on top of the server's php.ini.
 *
 * They are written into the site's PHP-FPM pool as php_value lines, so the application can still raise them with
 * ini_set(). Queue workers and the scheduler run on the command line and keep the server's settings.
 */
final class SitePhpSettings
{
    /**
     * Settings that have fields of their own, so they cannot also be given as other directives.
     */
    public const array MANAGED = ['memory_limit', 'upload_max_filesize', 'post_max_size', 'max_execution_time', 'max_input_vars'];

    public const string SIZE_PATTERN = '/^[1-9][0-9]{0,5}[KMG]?$/iD';

    public const string DIRECTIVE_NAME_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)*$/D';

    /**
     * Values may hold the constants and operators of settings like error_reporting, but nothing that could end the
     * pool config's line or start a new section in it.
     */
    public const string DIRECTIVE_VALUE_PATTERN = '/^[A-Za-z0-9 _.,:\/@%+~&|^!()-]*$/D';

    /**
     * nginx gives up on PHP after a minute by default, which would cut off longer requests.
     */
    private const int NGINX_DEFAULT_READ_TIMEOUT = 60;

    /**
     * @param  array{memory_limit?: string, upload_max_filesize?: string, max_execution_time?: int, max_input_vars?: int, directives?: array<string, string>}  $settings
     */
    public function __construct(private readonly array $settings) {}

    public static function for(Site $site): self
    {
        return new self($site->php_settings ?? []);
    }

    /**
     * Validation rules for the settings' fields.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'memory_limit' => ['nullable', 'string', 'max:10', 'regex:/^(-1|[1-9][0-9]{0,5}[KMG]?)$/iD'],
            'upload_max_filesize' => ['nullable', 'string', 'max:10', 'regex:'.self::SIZE_PATTERN],
            'max_execution_time' => ['nullable', 'integer', 'between:1,3600'],
            'max_input_vars' => ['nullable', 'integer', 'between:1,1000000'],
            'directives' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Build the settings to store from validated fields, or null when every field is empty.
     *
     * @param  array{memory_limit?: ?string, upload_max_filesize?: ?string, max_execution_time?: int|string|null, max_input_vars?: int|string|null, directives?: ?string}  $fields
     * @return array{memory_limit?: string, upload_max_filesize?: string, max_execution_time?: int, max_input_vars?: int, directives?: array<string, string>}|null
     *
     * @throws ValidationException
     */
    public static function fromFields(array $fields): ?array
    {
        $settings = array_filter([
            'memory_limit' => filled($fields['memory_limit'] ?? null) ? strtoupper($fields['memory_limit']) : null,
            'upload_max_filesize' => filled($fields['upload_max_filesize'] ?? null) ? strtoupper($fields['upload_max_filesize']) : null,
            'max_execution_time' => filled($fields['max_execution_time'] ?? null) ? (int) $fields['max_execution_time'] : null,
            'max_input_vars' => filled($fields['max_input_vars'] ?? null) ? (int) $fields['max_input_vars'] : null,
            'directives' => self::parseDirectives($fields['directives'] ?? '') ?: null,
        ], fn (mixed $value): bool => $value !== null);

        return $settings === [] ? null : $settings;
    }

    /**
     * Parse "name = value" lines. Blank lines and lines starting with ; or # are skipped.
     *
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public static function parseDirectives(?string $text): array
    {
        $directives = [];

        foreach (preg_split('/\R/', $text ?? '') as $number => $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, ';') || str_starts_with($line, '#')) {
                continue;
            }

            $fail = fn (string $message) => throw ValidationException::withMessages([
                'directives' => __('Line :number: :message', ['number' => $number + 1, 'message' => $message]),
            ]);

            if (! str_contains($line, '=')) {
                $fail(__('write each setting as name = value.'));
            }

            [$name, $value] = array_map(trim(...), explode('=', $line, 2));
            $name = strtolower($name);

            if (! preg_match(self::DIRECTIVE_NAME_PATTERN, $name)) {
                $fail(__(':name is not a PHP setting name.', ['name' => $name]));
            }

            if (in_array($name, self::MANAGED, true)) {
                $fail(__('set :name with its own field above.', ['name' => $name]));
            }

            if (strlen($value) > 255 || ! preg_match(self::DIRECTIVE_VALUE_PATTERN, $value)) {
                $fail(__('the value of :name can only contain letters, numbers, spaces and _ . , : / @ % + ~ & | ^ ! ( ) -', ['name' => $name]));
            }

            $directives[$name] = $value;
        }

        return $directives;
    }

    /**
     * The other directives as the lines they were entered as.
     */
    public function directivesText(): string
    {
        return collect($this->settings['directives'] ?? [])
            ->map(fn (string $value, string $name): string => "{$name} = {$value}")
            ->implode("\n");
    }

    public function get(string $name): string
    {
        return (string) ($this->settings[$name] ?? '');
    }

    /**
     * The php_value lines for the site's pool, keyed by setting name.
     *
     * @return array<string, string>
     */
    public function phpValues(): array
    {
        $upload = $this->settings['upload_max_filesize'] ?? null;

        return array_filter([
            'memory_limit' => $this->settings['memory_limit'] ?? null,
            'upload_max_filesize' => $upload,
            'post_max_size' => $upload,
            'max_execution_time' => isset($this->settings['max_execution_time']) ? (string) $this->settings['max_execution_time'] : null,
            'max_input_vars' => isset($this->settings['max_input_vars']) ? (string) $this->settings['max_input_vars'] : null,
        ], fn (?string $value): bool => $value !== null) + ($this->settings['directives'] ?? []);
    }

    /**
     * The largest request body nginx should pass on, so uploads up to the site's limit reach PHP.
     */
    public function clientMaxBodySize(): ?string
    {
        return isset($this->settings['upload_max_filesize']) ? strtolower($this->settings['upload_max_filesize']) : null;
    }

    /**
     * How long nginx should wait for PHP, in seconds, when the site's requests may run longer than nginx's default.
     */
    public function fastcgiReadTimeout(): ?int
    {
        $seconds = ($this->settings['max_execution_time'] ?? 0) + 10;

        return $seconds > self::NGINX_DEFAULT_READ_TIMEOUT ? $seconds : null;
    }
}
