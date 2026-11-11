set -Eeuo pipefail

export DEBIAN_FRONTEND=noninteractive

# Writing the PHP repository again only refreshes it, and moves a server still on the old PPA across.
@include('scripts.partials.php-repository')

@include('scripts.partials.install-php')

echo "PHP {!! $php->value !!} is installed. Its PHP-FPM pool and settings are written next."
