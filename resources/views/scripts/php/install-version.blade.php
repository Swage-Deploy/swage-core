set -Eeuo pipefail

export DEBIAN_FRONTEND=noninteractive

# Servers have had the ondrej PPA since they were provisioned. Adding it again only refreshes it.
add-apt-repository -y ppa:ondrej/php
apt-get update -q

@include('scripts.partials.install-php')

echo "PHP {!! $php->value !!} is installed. Its PHP-FPM pool and settings are written next."
