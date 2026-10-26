<?php

namespace App\SelfUpdate;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Checks that a version tag was signed by one of the keys in the release signers file, an SSH allowed signers file
 * that ships with Swage.
 *
 * Swage's own updates and official plugins are only installed from tags signed with these keys, so taking over a
 * repository or the account that pushes to it is not enough to ship code to every install. The file of the release
 * that is running decides, so the keys can only change through a release they signed. An install whose file lists no
 * keys, such as a fork that has not added its own, accepts tags without checking them.
 */
class ReleaseSignature
{
    public function __construct(protected ?string $signersFile = null) {}

    /**
     * The release signers file of the running release.
     */
    public function signersFile(): ?string
    {
        return $this->signersFile ?? config('swage.release_signers');
    }

    /**
     * Determine if tags must be signed, which they must once the signers file lists a key.
     */
    public function isRequired(): bool
    {
        $file = $this->signersFile();

        if (blank($file) || ! File::exists($file)) {
            return false;
        }

        return collect(explode("\n", File::get($file)))
            ->map(fn (string $line): string => trim($line))
            ->contains(fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'));
    }

    /**
     * Check the tag in a checkout and return the commit it points to.
     *
     * The tag's own name is checked as well as its signature, so an older signed tag cannot be passed off under a newer
     * version's name.
     *
     * @param  array<string, string>  $environment
     *
     * @throws RuntimeException when the tag is not signed by a release signer, or is not the tag it is named as.
     */
    public function verifyTag(string $directory, string $tag, array $environment = []): string
    {
        if (! GitRefs::isVersion($tag)) {
            throw new RuntimeException("{$tag} is not a version tag, and only signed version tags can be installed.");
        }

        $ref = "refs/tags/{$tag}";

        $verified = Process::path($directory)->env($environment)->timeout(60)->run([
            'git', '-c', 'gpg.format=ssh', '-c', 'gpg.ssh.allowedSignersFile='.$this->signersFile(), 'verify-tag', $ref,
        ]);

        if ($verified->failed()) {
            throw new RuntimeException(trim("{$tag} is not signed by a Swage release key, so it was not installed. ".trim($verified->errorOutput())));
        }

        $object = Process::path($directory)->env($environment)->run(['git', 'cat-file', 'tag', $ref]);
        $headers = $this->headers($object->output());

        if ($object->failed() || ($headers['tag'] ?? null) !== $tag || ($headers['type'] ?? null) !== 'commit' || ! preg_match('/^[0-9a-f]{40}$/D', $headers['object'] ?? '')) {
            throw new RuntimeException("The signed tag {$tag} was made for another version, so it was not installed.");
        }

        return $headers['object'];
    }

    /**
     * Read the headers of a tag object, which come before the first blank line.
     *
     * @return array<string, string>
     */
    protected function headers(string $tagObject): array
    {
        $headers = [];

        foreach (explode("\n", $tagObject) as $line) {
            if ($line === '') {
                break;
            }

            [$name, $value] = array_pad(explode(' ', $line, 2), 2, '');
            $headers[$name] ??= $value;
        }

        return $headers;
    }
}
