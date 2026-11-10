echo SWAGE_METRICS
head -n 1 /proc/stat
sleep 1
head -n 1 /proc/stat
grep -E '^(MemTotal|MemAvailable|SwapTotal|SwapFree):' /proc/meminfo
echo "loadavg $(cut -d ' ' -f 1-3 /proc/loadavg)"
echo "uptime $(cut -d ' ' -f 1 /proc/uptime)"
df -P -B 1 / | awk 'NR == 2 { print "disk", $2, $3 }'
