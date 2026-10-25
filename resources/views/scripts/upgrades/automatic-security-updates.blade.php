set -Eeuo pipefail

export DEBIAN_FRONTEND=noninteractive

@include('scripts.partials.unattended-upgrades')

echo "Automatic security updates turned on."
