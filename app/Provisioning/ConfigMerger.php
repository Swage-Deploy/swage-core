<?php

namespace App\Provisioning;

use App\Enums\ConfigSyntax;

/**
 * Gives user overrides precedence over the config Swage generates.
 *
 * Any setting the override defines is removed from Swage's config, so the two never conflict and
 * the user's value is the one the service uses, whatever order the files are loaded in.
 */
final class ConfigMerger
{
    /**
     * nginx directives that may appear more than once, so they are matched on their first argument too.
     */
    private const array NGINX_REPEATABLE = [
        'add_header', 'add_trailer', 'allow', 'deny', 'error_page', 'fastcgi_param', 'include',
        'listen', 'proxy_set_header', 'rewrite', 'set', 'uwsgi_param',
    ];

    /**
     * Redis directives that may appear more than once, so they are matched on their first argument too.
     */
    private const array REDIS_REPEATABLE = ['client-output-buffer-limit', 'include', 'loadmodule', 'rename-command', 'save', 'user'];

    /**
     * Remove every setting the override defines from Swage's config.
     *
     * For nginx, only statements directly inside the last top-level block named $block are considered.
     */
    public static function withoutOverridden(string $base, ?string $override, ConfigSyntax $syntax, ?string $block = null): string
    {
        $lines = array_filter(self::annotate($base, $override, $syntax, $block), fn (array $line): bool => ! $line['overridden']);

        return implode("\n", array_column($lines, 'line'));
    }

    /**
     * Split Swage's config into lines, marking the ones the override replaces.
     *
     * @return list<array{line: string, overridden: bool}>
     */
    public static function annotate(string $base, ?string $override, ConfigSyntax $syntax, ?string $block = null): array
    {
        $keys = array_flip(self::keys($override ?? '', $syntax));
        $lines = explode("\n", $base);

        if ($syntax === ConfigSyntax::Nginx) {
            return self::annotateNginx($lines, $keys, $block);
        }

        return array_map(fn (string $line): array => [
            'line' => $line,
            'overridden' => ($key = self::lineKey($line, $syntax)) !== null && isset($keys[$key]),
        ], $lines);
    }

    /**
     * The settings a config defines, in the form used to match them against Swage's config.
     *
     * @return list<string>
     */
    public static function keys(string $config, ConfigSyntax $syntax): array
    {
        if ($syntax === ConfigSyntax::Nginx) {
            return self::nginxKeys($config);
        }

        return array_values(array_unique(array_filter(array_map(
            fn (string $line): ?string => self::lineKey($line, $syntax),
            explode("\n", str_replace("\r\n", "\n", $config)),
        ))));
    }

    /**
     * The key of a single line of an INI or Redis style config, or null for comments, blanks and section headers.
     */
    private static function lineKey(string $line, ConfigSyntax $syntax): ?string
    {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';') || str_starts_with($line, '[')) {
            return null;
        }

        if ($syntax === ConfigSyntax::Redis) {
            $tokens = preg_split('/\s+/', strtolower($line));

            return in_array($tokens[0], self::REDIS_REPEATABLE, true) && isset($tokens[1]) ? "{$tokens[0]} {$tokens[1]}" : $tokens[0];
        }

        $key = strtolower(trim(explode('=', $line, 2)[0]));

        // MySQL treats dashes and underscores in option names as the same character.
        return $syntax === ConfigSyntax::Mysql ? str_replace('-', '_', $key) : $key;
    }

    /**
     * The keys of the top-level statements in an nginx snippet: directive names, and block headers such as "location /".
     *
     * @return list<string>
     */
    private static function nginxKeys(string $config): array
    {
        $keys = [];
        $statement = '';
        $depth = 0;
        $quote = null;
        $comment = false;
        $characters = mb_str_split($config);

        foreach ($characters as $index => $character) {
            if ($comment) {
                $comment = $character !== "\n";
            } elseif ($quote !== null) {
                $statement .= $character;

                if ($character === $quote && ($characters[$index - 1] ?? '') !== '\\') {
                    $quote = null;
                }
            } elseif ($character === '#') {
                $comment = true;
            } elseif ($character === '"' || $character === "'") {
                $quote = $character;
                $statement .= $character;
            } elseif ($character === '{') {
                if ($depth === 0) {
                    $keys[] = self::nginxBlockKey($statement);
                }

                $statement = '';
                $depth++;
            } elseif ($character === '}') {
                $statement = '';
                $depth = max(0, $depth - 1);
            } elseif ($character === ';') {
                if ($depth === 0) {
                    $keys[] = self::nginxDirectiveKey($statement);
                }

                $statement = '';
            } elseif ($depth === 0) {
                $statement .= $character;
            }
        }

        return array_values(array_unique(array_filter($keys)));
    }

    /**
     * Mark the statements inside the target block whose keys the override defines.
     *
     * @param  list<string>  $lines
     * @param  array<string, int>  $keys
     * @return list<array{line: string, overridden: bool}>
     */
    private static function annotateNginx(array $lines, array $keys, ?string $block): array
    {
        $target = self::lastTopLevelBlock($lines, $block);
        $annotated = [];
        $depth = 0;
        $skipUntilDepth = null;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            $opens = substr_count($line, '{');
            $closes = substr_count($line, '}');
            $overridden = $skipUntilDepth !== null;

            if ($skipUntilDepth === null && $target !== null && $index > $target && $depth === 1 && $trimmed !== '' && ! str_starts_with($trimmed, '#') && $trimmed !== '}') {
                $key = str_contains($trimmed, '{')
                    ? self::nginxBlockKey(strstr($trimmed, '{', true))
                    : self::nginxDirectiveKey(rtrim($trimmed, ';'));

                if (isset($keys[$key])) {
                    $overridden = true;
                    $skipUntilDepth = $opens > $closes ? $depth : null;
                }
            }

            $depth += $opens - $closes;

            if ($skipUntilDepth !== null && $depth <= $skipUntilDepth && $closes > 0) {
                $skipUntilDepth = null;
            }

            if ($target !== null && $index > $target && $depth === 0) {
                $target = null;
            }

            $annotated[] = ['line' => $line, 'overridden' => $overridden];
        }

        return $annotated;
    }

    /**
     * The line index of the last top-level block opened with the given name.
     *
     * @param  list<string>  $lines
     */
    private static function lastTopLevelBlock(array $lines, ?string $block): ?int
    {
        if ($block === null) {
            return null;
        }

        $depth = 0;
        $found = null;

        foreach ($lines as $index => $line) {
            if ($depth === 0 && preg_match('/^\s*'.preg_quote($block, '/').'\s*\{/', $line)) {
                $found = $index;
            }

            $depth += substr_count($line, '{') - substr_count($line, '}');
        }

        return $found;
    }

    private static function nginxDirectiveKey(string $statement): ?string
    {
        $tokens = preg_split('/\s+/', trim($statement), flags: PREG_SPLIT_NO_EMPTY);

        if ($tokens === []) {
            return null;
        }

        $name = strtolower($tokens[0]);

        return in_array($name, self::NGINX_REPEATABLE, true) && isset($tokens[1]) ? $name.' '.strtolower(trim($tokens[1], '"\'')) : $name;
    }

    private static function nginxBlockKey(string $header): ?string
    {
        $header = trim(preg_replace('/\s+/', ' ', $header));

        return $header === '' ? null : 'block:'.$header;
    }
}
