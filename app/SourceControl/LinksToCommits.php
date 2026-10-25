<?php

namespace App\SourceControl;

/**
 * A provider that links to commits on its web pages, which deployments link their commit to. Providers without it get a
 * link built from the clone URL, which suits most git hosts.
 */
interface LinksToCommits
{
    /**
     * The web page of a commit in a repository, such as https://github.com/acme/app/commit/{commit}.
     */
    public function commitUrl(string $repository, string $commit): string;
}
