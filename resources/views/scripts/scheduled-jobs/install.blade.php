set -Eeuo pipefail

@if ($scheduledJob->user === 'root')
mkdir -p /root/.swage
@else
sudo -u {!! $scheduledJob->user !!} mkdir -p /home/{!! $scheduledJob->user !!}/.swage
@endif

cat > '{!! $scheduledJob->logrotatePath() !!}' <<'SWAGE_EOF'
{!! $scheduledJob->logPath() !!} {
    su {!! $scheduledJob->user !!} {!! $scheduledJob->user !!}
    size 10M
    rotate 3
    compress
    missingok
    notifempty
    copytruncate
}
SWAGE_EOF

cat > '{!! $scheduledJob->cronPath() !!}' <<'SWAGE_EOF'
# Managed by Swage
SHELL=/bin/bash
{!! $scheduledJob->frequency !!} {!! $scheduledJob->user !!} {!! $scheduledJob->command !!} >> {!! $scheduledJob->logPath() !!} 2>&1
SWAGE_EOF

chmod 644 '{!! $scheduledJob->cronPath() !!}' '{!! $scheduledJob->logrotatePath() !!}'
