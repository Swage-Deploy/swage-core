#!/usr/bin/env bash
#
# Swage bootstrap for {!! $server->name !!}
#
# Checks the host, reports its hardware to Swage, then downloads and runs a
# provisioning script tuned to that hardware in the background.
#
set -euo pipefail

# Everything runs inside a function, so bash has read the whole script before
# anything executes and nothing can change it underneath.
main() {
    if [ "$(id -u)" -ne 0 ]; then
        echo "Swage must be provisioned as root." >&2
        exit 1
    fi

    . /etc/os-release

    if [ "$ID" != "ubuntu" ] || [ "$VERSION_ID" != "24.04" ]; then
        echo "Swage requires Ubuntu 24.04 (found $PRETTY_NAME)." >&2
        exit 1
    fi

    CPU_CORES=$(nproc)
    MEMORY_MB=$(awk '/^MemTotal:/ { print int($2 / 1024) }' /proc/meminfo)
    DISK_GB=$(df -BG --output=size / | tail -n 1 | tr -dc '0-9')
    # Swage pins this key before it ever connects over SSH, so the server must have one.
    if [ ! -f /etc/ssh/ssh_host_ed25519_key.pub ]; then
        ssh-keygen -A
        systemctl reload ssh 2> /dev/null || systemctl reload sshd 2> /dev/null || true
    fi

    HOST_KEY=$(awk '{ print $1 " " $2 }' /etc/ssh/ssh_host_ed25519_key.pub)

    if [ -z "$HOST_KEY" ]; then
        echo "Swage could not read the server's ed25519 SSH host key." >&2
        exit 1
    fi

    echo "Detected ${CPU_CORES} CPU core(s), ${MEMORY_MB}MB memory and ${DISK_GB}GB disk."

    curl -fsSL{!! $server->provisionsOverHttps() ? " --proto '=https'" : '' !!} -X POST '{!! route('provision.specs', $server->provision_token) !!}' \
        -d "cpu_cores=${CPU_CORES}" \
        -d "memory_mb=${MEMORY_MB}" \
        -d "disk_gb=${DISK_GB}" \
        -d "os_version=${VERSION_ID}" \
        --data-urlencode "host_key=${HOST_KEY}" \
        -o /root/swage-provision.sh

    chmod 700 /root/swage-provision.sh

    nohup bash /root/swage-provision.sh > /root/swage-provision.log 2>&1 &

    echo "Provisioning has started. Progress is shown in Swage, or run: tail -f /root/swage-provision.log"
}

main "$@"
exit
