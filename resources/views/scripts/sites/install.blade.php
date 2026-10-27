set -Eeuo pipefail

SITE_PATH='{!! $site->path() !!}'
@php($siteUser = $site->system_user)

# {!! $site->domain !!} runs as its own user. Its home is closed to other sites; only nginx joins its group to serve its files.
# Only an account Swage created for a site is reused. Any other account, such as a cloud image's sudo user, would hand
# the site that account's access.
if id {!! $siteUser !!} > /dev/null 2>&1; then
    if [ "$(getent passwd {!! $siteUser !!} | cut -d: -f5)" != '{!! App\Sites\SiteSystemUser::ACCOUNT_COMMENT !!}' ]; then
        echo "The user {!! $siteUser !!} already exists on this server. Create the site with another system user." >&2
        exit 1
    fi
else
    useradd --create-home --user-group --comment '{!! App\Sites\SiteSystemUser::ACCOUNT_COMMENT !!}' --shell /bin/bash {!! $siteUser !!}
fi
chmod 750 /home/{!! $siteUser !!}
usermod -aG {!! $siteUser !!} {!! $site->server->webUser() !!}
sudo -u {!! $siteUser !!} mkdir -p /home/{!! $siteUser !!}/.ssh /home/{!! $siteUser !!}/.swage
chmod 700 /home/{!! $siteUser !!}/.ssh

# Deployments reload PHP-FPM and restart the site's own workers through Swage's sudo helpers.
@include('scripts.partials.sudo-helpers')
@include('scripts.partials.sudoers', ['sudoUser' => $siteUser, 'sudoersFile' => 'swage-site-'.$siteUser])

@include('scripts.sites.php-pool')
@if ($site->private_redis)

@include('scripts.sites.private-redis')
@endif

sudo -u {!! $siteUser !!} mkdir -p "$SITE_PATH/releases" \
    "$SITE_PATH/shared/storage/app/public" \
    "$SITE_PATH/shared/storage/framework/cache/data" \
    "$SITE_PATH/shared/storage/framework/sessions" \
    "$SITE_PATH/shared/storage/framework/views" \
    "$SITE_PATH/shared/storage/logs"

if [ ! -f "$SITE_PATH/shared/.env" ]; then
    sudo -u {!! $siteUser !!} sh -c 'umask 077 && cat > "$1"' sh "$SITE_PATH/shared/.env" <<'SWAGE_EOF'
{!! $environment !!}
SWAGE_EOF
fi

# Serve a placeholder page until the first deployment activates a release.
if [ ! -e "$SITE_PATH/current" ]; then
    PLACEHOLDER="$SITE_PATH/releases/initial{!! rtrim('/'.ltrim($site->web_directory, '/'), '/') !!}"
    sudo -u {!! $siteUser !!} mkdir -p "$PLACEHOLDER"
    echo '{!! '<'.'?php' !!} echo "{!! $site->domain !!} is ready for its first deployment.";' | sudo -u {!! $siteUser !!} tee "$PLACEHOLDER/index.php" > /dev/null
    sudo -u {!! $siteUser !!} ln -sfn "$SITE_PATH/releases/initial" "$SITE_PATH/current"
fi

@include('scripts.sites.nginx-update')
