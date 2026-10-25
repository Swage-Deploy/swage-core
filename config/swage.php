<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Administrator
    |--------------------------------------------------------------------------
    |
    | The database seeder creates this account, so an email address must be
    | set. The installer uses the email given for Let's Encrypt notices. When
    | no password is set, a random one is generated and printed once.
    |
    */

    'admin' => [
        'name' => env('SWAGE_ADMIN_NAME', 'Administrator'),
        'email' => env('SWAGE_ADMIN_EMAIL'),
        'password' => env('SWAGE_ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | When enabled, anyone who can reach Swage may create an account at
    | /register. New accounts are never administrators.
    |
    */

    'registration' => (bool) env('SWAGE_REGISTRATION', false),

    /*
    |--------------------------------------------------------------------------
    | Require Two-Factor Authentication
    |--------------------------------------------------------------------------
    |
    | Users without confirmed two-factor authentication are sent to their
    | security settings to set it up before they can use Swage, and nobody
    | can turn it off. Set to false to make two-factor authentication optional.
    |
    */

    'require_two_factor' => (bool) env('SWAGE_REQUIRE_TWO_FACTOR', true),

    /*
    |--------------------------------------------------------------------------
    | Server User
    |--------------------------------------------------------------------------
    |
    | The unprivileged Unix user that owns sites and runs deployments on
    | every provisioned server.
    |
    */

    'user' => env('SWAGE_SERVER_USER', 'swage'),

    /*
    |--------------------------------------------------------------------------
    | Releases To Keep
    |--------------------------------------------------------------------------
    |
    | The number of zero-downtime releases kept on disk per site so that a
    | previous release is available to roll back to.
    |
    */

    'releases_to_keep' => (int) env('SWAGE_RELEASES_TO_KEEP', 4),

    /*
    |--------------------------------------------------------------------------
    | Deployment Queue
    |--------------------------------------------------------------------------
    |
    | The queue site deployments run on. Installs give deployments workers of
    | their own on the "deployments" queue, so long deployments never hold up
    | other tasks. Locally they share the default queue.
    |
    */

    'deployment_queue' => env('SWAGE_DEPLOYMENT_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Default Node Version
    |--------------------------------------------------------------------------
    */

    'node_version' => env('SWAGE_NODE_VERSION', '22'),

    /*
    |--------------------------------------------------------------------------
    | Certificate Email
    |--------------------------------------------------------------------------
    |
    | The email address registered with Let's Encrypt. Falls back to the
    | email address of the server owner when left empty.
    |
    */

    'certificate_email' => env('SWAGE_CERTIFICATE_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Plugins
    |--------------------------------------------------------------------------
    |
    | Plugins are installed from the plugin index. The list of installed plugins
    | and their checkouts live in storage, which every release shares, so each
    | update builds the same plugins into the new release.
    |
    */

    'plugins' => [
        // The list of plugins that can be installed. Only plugins listed here can be installed or updated.
        'index' => env('SWAGE_PLUGIN_INDEX', 'https://raw.githubusercontent.com/Swage-Deploy/plugin-repo/main/plugins.json'),
        'manifest' => env('SWAGE_PLUGIN_MANIFEST', storage_path('app/plugins.json')),
        'directory' => env('SWAGE_PLUGIN_DIRECTORY', storage_path('app/plugins')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Self Update
    |--------------------------------------------------------------------------
    |
    | Swage updates itself the same way it deploys zero downtime sites: a new
    | release is built beside the running one and only activated once every
    | step succeeds and the new release passes a health check.
    |
    */

    'self_update' => [
        'branch' => env('SWAGE_UPDATE_BRANCH', 'main'),
        'php_binary' => env('SWAGE_PHP_BINARY', 'php'),
        'php_fpm_service' => env('SWAGE_PHP_FPM_SERVICE', 'php8.5-fpm'),
        'releases_to_keep' => (int) env('SWAGE_UPDATE_RELEASES_TO_KEEP', 5),
        'health_check_url' => env('SWAGE_HEALTH_CHECK_URL'),
        'host_migrator' => env('SWAGE_HOST_MIGRATOR', '/usr/local/sbin/swage-host-migrate'),
        // Ubuntu creates this file when an update, such as a new kernel, needs a reboot. Unattended upgrades never reboot.
        'reboot_required_file' => env('SWAGE_REBOOT_REQUIRED_FILE', '/var/run/reboot-required'),
        // Root-owned command the app may run with sudo to reboot the host, which only does so once a reboot is required.
        'rebooter' => env('SWAGE_REBOOTER', '/usr/local/sbin/swage-reboot'),
    ],

];
