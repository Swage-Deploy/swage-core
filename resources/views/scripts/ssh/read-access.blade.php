set -Eeuo pipefail

# Ubuntu 24.04 starts sshd on demand, so its runtime directory may not exist yet, and sshd -T refuses to run without it.
install -d -m 755 /run/sshd

SSHD_CONFIG=$(sshd -T 2> /dev/null || true)

echo '{!! $marker !!} denied'
printf '%s\n' "$SSHD_CONFIG" | awk '$1 == "denyusers" { print $2 }'

echo '{!! $marker !!} keys-file'
printf '%s\n' "$SSHD_CONFIG" | awk '$1 == "authorizedkeysfile" { for (i = 2; i <= NF; i++) print $i }'

echo '{!! $marker !!} swage-denied'
if [ -f {!! App\Servers\SshAccess::CONFIG !!} ]; then
    awk '$1 == "DenyUsers" { for (i = 2; i <= NF; i++) print $i }' {!! App\Servers\SshAccess::CONFIG !!}
fi
@foreach ($sshUsers as $sshUser)

echo '{!! $marker !!} keys {!! $sshUser !!}'
@if ($sshUser === 'root')
cat /root/.ssh/authorized_keys 2> /dev/null || true
@else
sudo -n -u '{!! $sshUser !!}' cat '/home/{!! $sshUser !!}/.ssh/authorized_keys' 2> /dev/null || true
@endif
@endforeach
