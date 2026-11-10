# Moving to a new server

Swage can move itself to a new panel host. A script run on the new server connects to the old host over SSH as root and pulls everything across, then updates the servers Swage manages so they accept the new host.

## What moves

- Swage's configuration and secrets in `/etc/swage`, including a [Cloudflare Tunnel](/docs/cloudflare-tunnel)'s token.
- The Let's Encrypt certificate for the panel's domain.
- The running release, as it was built, with its dependencies and [plugins](/docs/plugins). The new host runs the same version as the old one.
- `.env` and storage, in `/home/swage/app/shared`. The `.env` holds the key that encrypts your servers' SSH keys and other secrets, so they work as before.
- The panel's database.
- Redis, which holds queued jobs and sessions, so nobody has to log in again.
- A [private fork](/docs/installation#installing-a-fork)'s deploy key.

The panel keeps its domain. Nothing on your servers needs new SSH keys: Swage's keys for them are in its database, which moves with it.

## Requirements

- A fresh **Ubuntu 24.04** server for the new host, with your SSH public key in its `/root/.ssh/authorized_keys`, as for [installing](/docs/installation#requirements).
- Root SSH access to the old host from the new one, with a key. The script gives you its key to add, and the move takes the key off again when it finishes.
- The old host updated to a recent version: one that has the `swage:host-moved` command, and no more than 100 commits behind the newest release. The script checks, and asks you to update the old host first if it is older.

## Run the move

As root on the new server:

```bash
apt-get update && apt-get install -y curl && curl -fsSL https://raw.githubusercontent.com/Swage-Deploy/swage-core/main/resources/scripts/move-swage.sh -o move-swage.sh && bash move-swage.sh
```

Pass the old host with `--from`, and `--port` when its SSH server is not on port 22, or the script asks for them:

```bash
bash move-swage.sh --from old.example.com --port 22
```

The script:

1. shows the old host's SSH key fingerprint for you to check against the one the old host shows with `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`,
2. prints a key to add to the old host's `/root/.ssh/authorized_keys`,
3. logs in to every server Swage manages from the new server, the way Swage does, as described [below](#the-servers-swage-manages),
4. copies Swage's configuration and checks out the same version,
5. asks you to type `move` to go ahead. Nothing on the old host or your servers has changed up to here,
6. puts the old panel into maintenance mode and stops its workers and scheduler, letting running deployments finish first, for up to 30 minutes,
7. sets up the new server with the same [host migrations](/docs/installation#what-the-installer-sets-up) as the old host, then copies the release, storage, database and Redis,
8. starts Swage on the new server,
9. updates fail2ban on your servers to leave the new server alone,
10. takes its key off the old host.

Everything it prints is also saved to `/root/swage-move.log`, readable by root only.

## If root cannot log in to the old host

The script tells you what went wrong and what to change on the old host, then waits for you to try again. The usual causes are:

- **The key is not in place.** Add the line the script printed to `/root/.ssh/authorized_keys` on the old host.
- **SSH does not let root log in.** See what SSH is using with `sshd -T | grep -i permitrootlogin`. It must be `prohibit-password` or `yes`. If it is `no`, find where it is set with `grep -rni permitrootlogin /etc/ssh/sshd_config /etc/ssh/sshd_config.d/`, change it to `PermitRootLogin prohibit-password`, then apply it with `sshd -t && systemctl reload ssh`. `AllowUsers`, `DenyUsers` and `AuthenticationMethods` can turn root away too. Swage's own hardening allows root key logins, so a host installed by Swage only needs this if you changed it.
- **The firewall or fail2ban keeps the new server out.** On the old host, `ufw allow from <new address> to any port 22` and `fail2ban-client unban <new address>`.

You can put the old host's SSH settings back once the move is finished.

## The servers Swage manages

Your servers accept SSH from anywhere with Swage's keys, so they should accept the new host as they did the old one. Before anything changes, the script checks: it logs in to each server from the new server as root, with the server's own key, and only when the server shows the host key Swage pinned for it. The old panel decrypts the keys for the check, which are deleted again as soon as it finishes.

If a server cannot be reached, the script lists it with the reason and what to look at on it, such as a firewall rule that only lets the old host in, or fail2ban banning the new server. Fix it and press Enter to check again, or type `skip` to move anyway.

Once Swage is running on the new host, the move runs `swage:host-moved`, which checks each server again. If the [security scanner](/docs/plugins#security-scanner) installed fail2ban on a server, it swaps the old host's address for the new one's among the addresses fail2ban never bans, and lifts any ban on the new one. A server that cannot be reached is listed at the end. Once it can, run the step again on the new host with the command the script prints, such as:

```bash
sudo -u swage php /home/swage/app/current/artisan swage:host-moved --from="198.51.100.7"
```

## After the move

Unless the panel is served through a Cloudflare Tunnel, point the panel's domain at the new server: change its `A` record (and `AAAA` record) to the new address. Until DNS changes, visitors and webhooks still reach the old host, which only shows that Swage is down for maintenance, and the certificate cannot be renewed on the new host.

Behind a Cloudflare Tunnel, the old host's connector is stopped and the new host's takes over, so the panel is served from the new host straight away.

The old host is left in maintenance mode, with its workers and scheduler stopped. Keep it until you are happy with the new host, then shut it down.

## If the move stops part way

Look at the end of `/root/swage-move.log` for the error, fix the cause, and run the script again. Each step is safe to repeat.

To give up on the move instead, start the old panel again by running this on the old host:

```bash
bash /root/swage-moved/restore.sh
```

It puts back the old panel's workers, scheduler and Cloudflare Tunnel connector and takes it out of maintenance mode. If the new server's tunnel connector had started, stop it with `systemctl disable --now swage-tunnel`, so the tunnel only reaches the old host. Don't run `restore.sh` once the new host is live: two panels would then act on the same servers.
