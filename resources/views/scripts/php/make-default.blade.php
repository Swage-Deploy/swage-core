set -Eeuo pipefail

# "php" on the command line, and Composer, which runs with it.
update-alternatives --set php /usr/bin/{!! $php->binary() !!}
php -v | head -n 1
