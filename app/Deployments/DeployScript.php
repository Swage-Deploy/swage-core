<?php

namespace App\Deployments;

use App\Models\Site;
use App\Sites\HooksIntoDeployments;
use App\Sites\SiteIntegrations;
use App\Ssh\Script;

class DeployScript
{
    /**
     * Macros available in a site's deploy script and the templates they expand to.
     *
     * @var array<string, string>
     */
    public const array MACROS = [
        '$CREATE_RELEASE()' => 'sites.macros.create-release',
        '$ACTIVATE_RELEASE()' => 'sites.macros.activate-release',
        '$RESTART_QUEUES()' => 'sites.macros.restart-queues',
    ];

    /**
     * The deploy script given to new sites.
     */
    public static function default(): string
    {
        return <<<'BASH'
            $CREATE_RELEASE()

            cd $SWAGE_RELEASE_DIRECTORY

            composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

            if [ -f package.json ]; then
                npm ci
                npm run build
            fi

            php artisan migrate --force
            php artisan cache:clear
            php artisan optimize
            php artisan storage:link

            $ACTIVATE_RELEASE()

            $RESTART_QUEUES()
            BASH;
    }

    /**
     * Build the full script that deploys the site.
     */
    public static function render(Site $site): string
    {
        $site->loadMissing('daemons');

        $script = str_replace("\r\n", "\n", $site->deploy_script ?? self::default());

        foreach (self::MACROS as $macro => $template) {
            $script = str_replace($macro, trim(Script::render($template, ['site' => $site]).self::integrationSteps($site, $macro)), $script);
        }

        return Script::render('sites.deploy', ['site' => $site, 'script' => $script]);
    }

    /**
     * The steps site integrations add after a macro.
     */
    protected static function integrationSteps(Site $site, string $macro): string
    {
        $hook = match ($macro) {
            '$CREATE_RELEASE()' => 'afterCreatingRelease',
            '$ACTIVATE_RELEASE()' => 'afterActivatingRelease',
            default => null,
        };

        if ($hook === null) {
            return '';
        }

        return collect(app(SiteIntegrations::class)->all())
            ->whereInstanceOf(HooksIntoDeployments::class)
            ->map(fn (HooksIntoDeployments $integration): string => trim($integration->{$hook}($site)))
            ->filter()
            ->map(fn (string $steps): string => "\n".$steps)
            ->implode('');
    }

    /**
     * Extract the commit details printed by the create release macro.
     *
     * @return array{commit_hash?: string, commit_author?: string, commit_message?: string}
     */
    public static function commitDetails(string $output): array
    {
        $fields = ['SWAGE_COMMIT' => 'commit_hash', 'SWAGE_AUTHOR' => 'commit_author', 'SWAGE_MESSAGE' => 'commit_message'];

        $details = [];

        foreach (explode("\n", $output) as $line) {
            foreach ($fields as $marker => $field) {
                if (str_starts_with($line, $marker.' ')) {
                    $details[$field] = trim(substr($line, strlen($marker) + 1));
                }
            }
        }

        return $details;
    }
}
