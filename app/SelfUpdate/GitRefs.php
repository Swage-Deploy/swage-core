<?php

namespace App\SelfUpdate;

/**
 * Reads the refs git ls-remote lists and picks the newest version tag among them. Swage and its plugins are both
 * released through version tags.
 */
final class GitRefs
{
    /**
     * Tags treated as versions: 1, 1.2 or 1.2.3, optionally prefixed with v. Pre-releases are left out.
     */
    public const string VERSION_TAG = '/^v?\d+(\.\d+){0,2}$/D';

    /**
     * The branches and tags in git ls-remote output, with the commits they point to, and the default branch when the
     * output was listed with --symref.
     *
     * @return array{branches: array<string, string>, tags: array<string, string>, head: ?string}
     */
    public static function parse(string $output): array
    {
        $refs = ['branches' => [], 'tags' => [], 'head' => null];

        foreach (preg_split('/\R/', trim($output)) as $line) {
            if (preg_match('/^ref: refs\/heads\/(\S+)\s+HEAD$/', trim($line), $matches)) {
                $refs['head'] = $matches[1];

                continue;
            }

            [$commit, $ref] = array_pad(preg_split('/\s+/', trim($line), 2), 2, '');

            if (str_starts_with($ref, 'refs/heads/')) {
                $refs['branches'][substr($ref, strlen('refs/heads/'))] = $commit;
            } elseif (str_starts_with($ref, 'refs/tags/')) {
                // An annotated tag is listed twice; the ^{} line, listed last, holds the commit it points to.
                $refs['tags'][preg_replace('/\^\{\}$/', '', substr($ref, strlen('refs/tags/')))] = $commit;
            }
        }

        return $refs;
    }

    /**
     * @param  array<string, string>  $tags
     */
    public static function newestVersion(array $tags): ?string
    {
        $versions = array_filter(array_keys($tags), fn (string $tag): bool => self::isVersion($tag));
        usort($versions, fn (string $a, string $b): int => version_compare(ltrim($b, 'v'), ltrim($a, 'v')));

        return $versions[0] ?? null;
    }

    public static function isVersion(string $ref): bool
    {
        return (bool) preg_match(self::VERSION_TAG, $ref);
    }

    /**
     * Determine if one version is newer than another, ignoring a v prefix.
     */
    public static function isNewer(string $version, string $than): bool
    {
        return version_compare(ltrim($version, 'v'), ltrim($than, 'v'), '>');
    }
}
