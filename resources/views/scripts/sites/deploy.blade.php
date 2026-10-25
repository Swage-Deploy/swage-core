set -Eeuo pipefail

export SWAGE_SITE_PATH='{!! $site->path() !!}'
export SWAGE_SITE_BRANCH='{!! $site->branch !!}'
@if ($site->zero_downtime)
export SWAGE_RELEASE_NAME="$(date +%Y%m%d%H%M%S)"
export SWAGE_RELEASE_DIRECTORY="$SWAGE_SITE_PATH/releases/$SWAGE_RELEASE_NAME"

# A release that never went live is deleted when the deployment fails, so failed builds neither fill the disk nor
# push working releases out of the ones kept to roll back to.
swage_discard_failed_release() {
    local status=$?

    if [ "$status" -ne 0 ] && [ -z "${SWAGE_RELEASE_ACTIVATED:-}" ] && [ -d "$SWAGE_RELEASE_DIRECTORY" ] \
        && [ "$(readlink -f "$SWAGE_SITE_PATH/current")" != "$(readlink -f "$SWAGE_RELEASE_DIRECTORY")" ]; then
        echo "Removing release $SWAGE_RELEASE_NAME, which never went live"
        rm -rf "$SWAGE_RELEASE_DIRECTORY"
    fi

    exit "$status"
}

trap swage_discard_failed_release EXIT
@else
export SWAGE_RELEASE_DIRECTORY="$SWAGE_SITE_PATH/current"
@endif
export SWAGE_PHP='{!! $site->php_version->binary() !!}'
export SWAGE_PHP_FPM='{!! $site->php_version->fpmService() !!}'
export GIT_SSH_COMMAND="ssh -i '{!! $site->deployKeyPath() !!}' -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new"

# Make "php" resolve to the site's PHP version, including inside Composer.
PHP_SHIM="$HOME/.swage/php/{!! $site->php_version->value !!}"
mkdir -p "$PHP_SHIM"
ln -sf "/usr/bin/$SWAGE_PHP" "$PHP_SHIM/php"
export PATH="$PHP_SHIM:$PATH"

# Swage's sudo helpers only act on this PHP version and this user's own workers. Servers that have not
# had the sudo upgrade yet fall back to the rules they were provisioned with.
swage_reload_php() {
    sudo -n /usr/local/sbin/swage-reload-php '{!! $site->php_version->value !!}' 2> /dev/null || sudo -n service "$SWAGE_PHP_FPM" reload
}

swage_restart_daemon() {
    sudo -n /usr/local/sbin/swage-restart-daemon "$1" 2> /dev/null || sudo -n supervisorctl restart "daemon-$1:*"
}

cd "$SWAGE_SITE_PATH"

{!! $script !!}
