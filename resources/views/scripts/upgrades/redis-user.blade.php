set -Eeuo pipefail

# Only the password's hash is passed to Redis, so the password never appears in the process list.
redis-cli ACL SETUSER {!! $user !!} reset on '#{!! hash('sha256', $password) !!}' '~*' '&*' '+@all' | grep -q OK

echo "Added the {!! $user !!} Redis user."
