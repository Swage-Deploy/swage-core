set -Eeuo pipefail

export DEBIAN_FRONTEND=noninteractive

systemctl disable --now {!! $php->fpmService() !!} || true

SWAGE_PACKAGES=$(dpkg-query -W -f='${Package} ${Status}\n' '{!! $php->binary() !!}-*' '{!! $php->binary() !!}' 2> /dev/null | awk '$NF == "installed" { print $1 }' || true)
if [ -n "$SWAGE_PACKAGES" ]; then
    # shellcheck disable=SC2086
    apt-get purge -yq $SWAGE_PACKAGES
fi
apt-get autoremove -yq

# Purging leaves the pools and settings Swage wrote, which no package owns.
rm -rf '/etc/php/{!! $php->value !!}'

echo "PHP {!! $php->value !!} is removed. The other versions are re-tuned next."
