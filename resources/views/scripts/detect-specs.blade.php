set -Eeuo pipefail

echo "SWAGE_CPU_CORES $(nproc)"
echo "SWAGE_MEMORY_MB $(awk '/^MemTotal:/ { print int($2 / 1024) }' /proc/meminfo)"
echo "SWAGE_DISK_GB $(df -BG --output=size / | tail -n 1 | tr -dc '0-9')"
