<?php

namespace App\Plugins;

use App\SourceControl\GitUrl;

/**
 * Links a plugin to the web page of the git repository it is installed from.
 */
trait LinksToRepository
{
    /**
     * The repository's web page, or null when the git URL cannot be turned into one.
     */
    public function repositoryUrl(): ?string
    {
        return GitUrl::webUrl($this->repository);
    }
}
