set -Eeuo pipefail

rm -f '{!! $daemon->configPath() !!}'
supervisorctl reread
supervisorctl update
