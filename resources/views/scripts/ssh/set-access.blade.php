set -Eeuo pipefail

@include('scripts.ssh.partials.deny-users')
@unless ($enabled)

# End {!! $sshUser !!}'s open SSH sessions. Its site keeps running.
pkill -KILL -u '{!! $sshUser !!}' sshd || true
@endunless
