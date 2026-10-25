set -Eeuo pipefail

@include('scripts.partials.write-files', ['files' => $files])

# The config now turns anonymous access off on restart; this turns it off on the running server without one.
if redis-cli ping 2>&1 | grep -q PONG; then
    redis-cli ACL SETUSER default off | grep -q OK
fi

echo "Redis now requires a password."
