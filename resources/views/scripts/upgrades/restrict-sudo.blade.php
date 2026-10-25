set -Eeuo pipefail

@include('scripts.partials.sudo-helpers')

@include('scripts.partials.sudoers', ['sudoUser' => $user, 'sudoersFile' => 'swage'])
@foreach ($isolatedSites as $site)

if id {!! $site->system_user !!} > /dev/null 2>&1; then
@include('scripts.partials.sudoers', ['sudoUser' => $site->system_user, 'sudoersFile' => 'swage-site-'.$site->system_user])
fi
@endforeach

echo "Sudo rules restricted to Swage's helpers."
