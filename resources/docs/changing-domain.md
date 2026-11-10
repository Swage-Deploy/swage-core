# Changing the domain

You can serve the panel from a new domain, such as when you move from `swage.example.com` to `deploy.example.com`. The panel stays on the same server. To move it to another server instead, see [Moving to a new server](/docs/moving).

## Before you start

- Update the panel, so it has the `swage-change-domain` command. See [Updating](/docs/updating).
- Point the new domain's DNS at the panel host. Behind a [Cloudflare Tunnel](/docs/cloudflare-tunnel), add a public hostname for it to the tunnel instead.

## Change the domain

As root on the panel host:

```bash
swage-change-domain deploy.example.com
```

It:

1. checks that the new domain resolves to the server, and stops if it does not. Behind a tunnel this check is skipped.
2. requests a Let's Encrypt certificate for the new domain.
3. serves the panel from the new domain and updates `APP_URL`.
4. removes the old domain's certificate. The old domain stops working at this point.
5. points every [quick deploy](/docs/deployments) webhook on GitHub or GitLab at the new domain.

If a webhook cannot be moved, such as when its owner has disconnected their account, the command names the site. Turn **Quick deploy** off and on again on that site to fix it.

## Update these by hand

- **OAuth apps.** Change the callback URL of the GitHub or GitLab OAuth app the [source control](/docs/source-control) plugins use to the new domain. Until you do, connecting an account fails.
- **Deployment trigger URLs.** Each site's trigger URL now starts with the new domain. Update any CI job that calls one.
- **Cloudflare Access.** If [Access](/docs/cloudflare-access) protects the panel, move its applications to the new domain.
