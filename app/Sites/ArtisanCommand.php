<?php

namespace App\Sites;

use App\Models\Site;
use InvalidArgumentException;

/**
 * An Artisan command typed as what follows "php artisan", such as migrate --force or "about --only=environment".
 *
 * The input is split into arguments the way a shell would, honouring quotes and backslashes, and each argument is
 * quoted again before it reaches the server, so nothing typed can do more than pass arguments to Artisan.
 */
final class ArtisanCommand
{
    /**
     * Commands that keep running or wait for someone at a keyboard, which belong in a daemon or an SSH session.
     *
     * @var list<string>
     */
    public const array LONG_RUNNING = [
        'tinker', 'serve', 'db', 'pail', 'horizon', 'queue:work', 'queue:listen', 'schedule:work',
        'octane:start', 'octane:frankenphp', 'octane:roadrunner', 'octane:swoole', 'reverb:start',
        'pulse:work', 'pulse:check', 'nightwatch:agent',
    ];

    /**
     * @param  list<string>  $arguments  The command's name followed by its arguments.
     */
    private function __construct(public readonly array $arguments) {}

    /**
     * @throws InvalidArgumentException with a message for the person who typed it.
     */
    public static function parse(string $input): self
    {
        $input = trim($input);

        if (str_starts_with($input, 'php artisan ')) {
            $input = trim(substr($input, strlen('php artisan ')));
        }

        if ($input === '') {
            throw new InvalidArgumentException(__('Enter a command, such as migrate --force.'));
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $input)) {
            throw new InvalidArgumentException(__('The command must be on one line.'));
        }

        $arguments = self::split($input);
        $name = $arguments[0];

        if (! preg_match('/^[A-Za-z][A-Za-z0-9:_.-]*$/D', $name)) {
            throw new InvalidArgumentException(__('Start with the name of an Artisan command, such as migrate.'));
        }

        if (in_array(strtolower($name), self::LONG_RUNNING, true)) {
            throw new InvalidArgumentException(__(':command keeps running or waits for input, so it cannot be run from here. Run it as a daemon, or over SSH.', ['command' => $name]));
        }

        return new self($arguments);
    }

    /**
     * The shell script that runs the command in the site's live release with its PHP version, never asking anything.
     */
    public function script(Site $site): string
    {
        $arguments = collect([...$this->arguments, '--no-interaction'])->map(fn (string $argument): string => escapeshellarg($argument))->implode(' ');
        $release = escapeshellarg($site->currentPath());

        return <<<BASH
            cd {$release} 2> /dev/null && [ -f artisan ] || { echo "The site has no live release with an artisan file yet. Deploy it first." >&2; exit 1; }
            exec {$site->php_version->binary()} artisan {$arguments}
            BASH;
    }

    /**
     * Split the input into arguments, honouring single quotes, double quotes and backslash escapes.
     *
     * @return non-empty-list<string>
     *
     * @throws InvalidArgumentException
     */
    protected static function split(string $input): array
    {
        $arguments = [];
        $current = null;
        $quote = null;

        for ($i = 0, $length = strlen($input); $i < $length; $i++) {
            $character = $input[$i];

            if ($quote === "'") {
                $character === "'" ? $quote = null : $current .= $character;
            } elseif ($character === '\\' && $i + 1 < $length) {
                $current .= $input[++$i];
            } elseif ($quote === '"') {
                $character === '"' ? $quote = null : $current .= $character;
            } elseif ($character === "'" || $character === '"') {
                $quote = $character;
                $current ??= '';
            } elseif ($character === ' ') {
                if ($current !== null) {
                    $arguments[] = $current;
                    $current = null;
                }
            } else {
                $current .= $character;
            }
        }

        if ($quote !== null) {
            throw new InvalidArgumentException(__('A quote is not closed.'));
        }

        if ($current !== null) {
            $arguments[] = $current;
        }

        return $arguments;
    }
}
