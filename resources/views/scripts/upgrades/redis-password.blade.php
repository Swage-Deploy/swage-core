set -Eeuo pipefail

{{-- redis-cli reads the password from REDISCLI_AUTH, and only its hash is passed to Redis, so neither shows in the process list. --}}
@if ($files === null)
# The new password works beside the old one until every site has switched to it.
REDISCLI_AUTH='{!! $current !!}' redis-cli --no-auth-warning --user {!! $user !!} ACL SETUSER {!! $user !!} '#{!! hash('sha256', $password) !!}' | grep -q OK

echo "Added a new password for the {!! $user !!} Redis user."
@else
@include('scripts.partials.write-files', ['files' => $files])

# Every site uses the new password now, so the old one is removed.
REDISCLI_AUTH='{!! $current !!}' redis-cli --no-auth-warning --user {!! $user !!} ACL SETUSER {!! $user !!} reset on '#{!! hash('sha256', $password) !!}' '~*' '&*' '+@all' | grep -q OK

echo "Removed the old password of the {!! $user !!} Redis user."
@endif
