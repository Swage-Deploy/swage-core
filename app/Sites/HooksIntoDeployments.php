<?php

namespace App\Sites;

use App\Models\Site;

/**
 * A site integration that adds steps to a site's deploy script. Each step is bash that runs as the site's user, and
 * may use the deploy script's variables, such as $SWAGE_RELEASE_DIRECTORY and $SWAGE_COMMIT_HASH. Return an empty
 * string to add nothing, such as for a site the integration is not set up for.
 *
 * Steps are only added where the deploy script uses the $CREATE_RELEASE() and $ACTIVATE_RELEASE() macros.
 */
interface HooksIntoDeployments
{
    /**
     * Steps run once the release holds the new commit, before it is built.
     */
    public function afterCreatingRelease(Site $site): string;

    /**
     * Steps run once the release is live.
     */
    public function afterActivatingRelease(Site $site): string;
}
