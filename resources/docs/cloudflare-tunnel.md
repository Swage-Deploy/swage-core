# Cloudflare Tunnel

A Cloudflare Tunnel serves the panel without opening ports 80 and 443. `cloudflared` on the panel host connects out to Cloudflare, Cloudflare serves HTTPS on your domain, and only SSH stays open in the firewall.

The domain does not need to resolve to the server: Cloudflare routes it to the tunnel.

## 1. Create the tunnel

In the Cloudflare dashboard, go to **Zero Trust → Networks → Tunnels**, create a **Cloudflared** tunnel and copy its **token**: the long value at the end of the install command Cloudflare shows. You do not need to run that command. The Swage installer installs `cloudflared` itself.

## 2. Route the domain to nginx

Add a **public hostname** to the tunnel:

| Setting | Value |
|---|---|
| Subdomain and domain | The panel's domain, such as `swage.example.com` |
| Service type | `HTTP` |
| URL | `localhost:80` |

Leave **HTTP Host Header** under the additional settings empty, so nginx receives the panel's own domain.

> **Note:** Use `HTTP`, not `HTTPS`. Cloudflare terminates TLS, and the hop from `cloudflared` to nginx never leaves the server.

## 3. Run the installer with the token

Run the [install command](/docs/installation#run-the-installer) with the token at the end:

```bash
apt-get update && apt-get install -y curl && curl -fsSL https://raw.githubusercontent.com/Swage-Deploy/swage-core/main/resources/scripts/install-swage.sh -o install-swage.sh && bash install-swage.sh --cloudflare-tunnel-token <token>
```

Behind a tunnel the installer does not check where the domain resolves, and the email address it asks for is only used for the administrator account.

With a token, the installer:

- installs `cloudflared` from Cloudflare's apt repository and runs it as the `swage-tunnel` systemd service. The token is kept in `/etc/swage/cloudflared.env`, readable by root only, and never appears in the process list.
- makes nginx listen on `127.0.0.1:80` and `[::1]:80` only, tells PHP that requests arrived over HTTPS, and takes each visitor's address from Cloudflare's `CF-Connecting-IP` header, so rate limiting and logs see the real visitor.
- skips the Let's Encrypt certificate and closes ports 80 and 443 in the firewall.
- sets `SWAGE_HEALTH_CHECK_URL=http://127.0.0.1/up`, so the health check after each self-update never goes through Cloudflare.

## Managing the tunnel

The tunnel is a normal systemd service:

```bash
systemctl status swage-tunnel
journalctl -u swage-tunnel -n 100
systemctl restart swage-tunnel
```

To change the token, edit `/etc/swage/cloudflared.env` and restart the service.

### Forcing HTTP/2

`cloudflared` connects over QUIC by default, which uses UDP. Some networks and containers, such as LXC containers behind certain firewalls, drop or time out UDP, and the tunnel never connects. Cloudflare then shows **error 530**. Switch the tunnel to HTTP/2 over TCP:

```bash
echo 'TUNNEL_TRANSPORT_PROTOCOL=http2' >> /etc/swage/cloudflared.env
systemctl restart swage-tunnel
```

## Sites on managed servers

The tunnel only serves the panel. Servers that Swage manages serve their sites directly, with Let's Encrypt certificates. If you put a site behind Cloudflare's proxy, set the zone's SSL mode to **Full (strict)** once its certificate is issued.

## Next steps

To put a login in front of the panel as well, see [Cloudflare Access](/docs/cloudflare-access).
