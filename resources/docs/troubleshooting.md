# Troubleshooting

## Installing

### The install stopped part way

The full output is in `/root/swage-install.log`. Fix the cause and run the installer again. It fetches the newest release and continues from the first step that did not finish.

### The installer says Swage is already installed

The panel is already installed, so the installer will not run over it. Update it from the **Updates** page or with `swage:update` instead.

### The panel shows nginx's default page

nginx was not restarted after its config changed. Restart it:

```bash
systemctl restart nginx
```

### The browser cannot resolve the panel's domain

A browser that looked the domain up before its DNS record existed may have cached the failure. In Chrome, open `chrome://net-internals/#dns` and choose **Clear host cache**, then `chrome://net-internals/#sockets` and **Flush socket pools**. On macOS, also flush the system's cache:

```bash
sudo dscacheutil -flushcache; sudo killall -HUP mDNSResponder
```

## Logging in

### The administrator password is lost

On the panel host, as root, this sets a new random password and prints it:

```bash
sudo -u swage php /home/swage/app/current/artisan tinker --execute '$password = Str::password(20); App\Models\User::where("email", config("swage.admin.email"))->firstOrFail()->update(["password" => $password]); echo $password.PHP_EOL;'
```

### Two-factor codes are rejected

Codes depend on the time. Check that the clock on your phone and on the panel host are right, or log in with a recovery code.

### Someone lost their password or two-factor device

An administrator can give them a new password or turn off their two-factor authentication from the **Users** page. See [Users](/docs/users#managing-a-user).

### A page sends you back to the dashboard while signed in as someone

Account settings, such as the password, two-factor authentication, passkeys and connected source control accounts, are not available while you are signed in as another user. Stop impersonating first.

## Cloudflare

### Cloudflare shows error 530

The tunnel is not connected. Check the service:

```bash
systemctl status swage-tunnel
journalctl -u swage-tunnel -n 100
```

If the log shows QUIC or UDP timeouts, the network is blocking UDP. Switch the tunnel to HTTP/2:

```bash
echo 'TUNNEL_TRANSPORT_PROTOCOL=http2' >> /etc/swage/cloudflared.env
systemctl restart swage-tunnel
```

### Provisioning or webhooks hang behind Cloudflare Access

`provision/*`, `webhooks/*` and `deploy/*` must bypass Access. See [Cloudflare Access](/docs/cloudflare-access).

## Servers

### Provisioning stopped making progress

The server's page notes when the server has not reported progress for a while. Check `/root/swage-provision.log` on the server. If the script is no longer running, such as after a reboot, choose **Start over** for a new command and run it again.

### The provisioning command fails with `curl: command not found`

The command installs `curl` itself when it is missing. Copy the command again from the server's page if yours came from an older version of Swage.

### A task on a server failed

The server's **Activity** page has the output of every script Swage ran there, and its **Logs** page shows the service logs.

### Swage refuses to connect to a rebuilt server

Swage pins each server's SSH host key. Once you know the rebuild was yours, trust the new key from the server's overview page.

### A config override did not apply

Swage restored the previous config because the new one failed validation or the service did not come back healthy. The error is on the **Config** page, and the full output is on the **Activity** page.

## Sites

### Connecting a source control account fails

The **Source control** page says why. The usual causes:

- **The provider does not accept the callback URL.** Set the OAuth app's callback URL to the one on the provider's **Configure** page.
- **The client ID and secret were not accepted.** Check them on the provider's **Configure** page against the OAuth app.
- **Your sign-in was not found.** The provider sent you back to a different address from the one you were signed in at. Open Swage at the address in `APP_URL` and connect again.

### Members cannot install a repository from GitHub or turn on quick deploy

Swage acts through the server owner's connected account, so only the owner can. Members can still install a custom git repository and deploy.

### GitHub says the app is not allowed to add deploy keys or create webhooks

Installing a repository needs the GitHub App's **Administration** repository permission, and quick deploy needs its **Webhooks** repository permission, both read and write. In the app's settings on GitHub, set them under **Repository permissions**, not the organization permissions with the same names. Then accept the new permissions on the app's installation, under the account's or organization's **Settings → GitHub Apps**, and try again. Older versions of the plugin report this as "GitHub: Resource not accessible by integration". See [Source control](/docs/source-control#setting-up-a-provider).

### A provider says the account is not connected

The account was disconnected, or its token was revoked. Reconnect it from **Source control** in the sidebar. Deployments keep working meanwhile; only installing repositories and changing quick deploy need the connection.

### A deployment failed

Open the deployment from the site's page to read the output of its deploy script. With zero-downtime deployments, the failed release never went live and has already been removed.

### Pushes do not deploy

Check that **Quick deploy** is on and that the push was to the site's branch. The provider's webhook settings show each delivery and the panel's response. For a custom repository, call the [deploy trigger URL](/docs/deployments#triggering-a-deployment) from your git host or CI.

### A certificate cannot be obtained

Every domain and alias must already point at the server, and port 80 must be reachable, because Let's Encrypt checks each domain over HTTP. If the domain is proxied by Cloudflare, make sure Cloudflare is not redirecting HTTP to HTTPS until the certificate is issued.

### A certificate has not renewed

The site's **SSL** page and the server's overview page say when a certificate is past the point Certbot renews it. Usually a domain no longer reaches the server over HTTP, or Cloudflare is challenging requests to `/.well-known/acme-challenge/`. Run `certbot renew` on the server to see why, or choose **Obtain certificate** again.

### A site behind Cloudflare is stuck in a redirect loop

Cloudflare's SSL/TLS mode is on **Flexible**, so it connects to the server over HTTP, which nginx redirects to HTTPS. Set it to **Full (strict)**. See [Site settings](/docs/site-settings#behind-cloudflares-proxy).

### An Artisan command is refused

Commands that keep running or wait for input, such as `tinker` or `queue:work`, cannot run from the **Commands** tab. Run them as a [daemon](/docs/daemons-and-scheduler) or over SSH. Other commands need the site to be deployed first.
