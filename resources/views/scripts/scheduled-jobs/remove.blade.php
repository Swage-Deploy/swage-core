set -Eeuo pipefail

rm -f '{!! $scheduledJob->cronPath() !!}' '{!! $scheduledJob->logrotatePath() !!}' '{!! $scheduledJob->logPath() !!}'*
