<?php

namespace App\SourceControl;

final class GitUrl
{
    /**
     * The web page of the repository a git URL points to, such as https://github.com/acme/app for
     * git@github.com:acme/app.git, or null when the URL cannot be turned into one.
     */
    public static function webUrl(string $gitUrl): ?string
    {
        $url = match (true) {
            (bool) preg_match('/^git@([^:\/\s]+):(\S+)$/D', $gitUrl, $matches) => "https://{$matches[1]}/{$matches[2]}",
            (bool) preg_match('/^ssh:\/\/(?:[^@\/\s]+@)?([^:\/\s]+)(?::\d+)?\/(\S+)$/D', $gitUrl, $matches) => "https://{$matches[1]}/{$matches[2]}",
            str_starts_with($gitUrl, 'https://') => $gitUrl,
            default => null,
        };

        return $url === null ? null : preg_replace('/\.git$/', '', rtrim($url, '/'));
    }
}
