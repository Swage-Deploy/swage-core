# Cloudflare Access

You can put the panel behind a Cloudflare Access login, such as your company's identity provider, on top of Swage's own login and two-factor authentication. It works whether or not the panel uses a [Cloudflare Tunnel](/docs/cloudflare-tunnel), as long as the domain is proxied by Cloudflare.

## Paths that must bypass Access

Servers and git hosts call a few of the panel's URLs without a browser, so they cannot log in to Access. Each of these paths is already protected by its own secret:

| Path | Called by | Protected by |
|---|---|---|
| `provision/*` | Servers reporting back while they are provisioned | A 64-character provisioning token, valid until the server is active |
| `webhooks/*` | Push webhooks from GitHub, GitLab and other providers, for quick deploy | A secret per site, checked the way each provider signs its deliveries |
| `deploy/*` | Deploy trigger URLs called from CI | A 48-character deploy token |

If these paths are behind Access, provisioning hangs at its first step, and pushes and CI never deploy.

If scripts or CI use the [API](/docs/api) from outside Access, also bypass `api/*`, which is protected by API keys. Or keep it behind Access and send an Access service token with each request.

## Setting up the applications

In **Zero Trust → Access → Applications**:

1. Add a **Self-hosted** application for the panel's domain, with the policy that should protect the panel, such as an email domain or an identity provider group.
2. Add a second **Self-hosted** application on the same domain, with three destinations: the paths `provision/*`, `webhooks/*` and `deploy/*`. Give it a single policy:
   - **Action:** Bypass
   - **Include:** Everyone

Cloudflare applies the most specific matching path, so only those three paths skip the login.

## What else keeps working

- **Self-updates.** The health check after each update is made from the panel host itself and does not go through Access.
- **SSH to your servers.** Swage connects to servers directly over SSH, not through Cloudflare.
- **Connecting GitHub or GitLab.** The OAuth callback happens in your browser, which is already logged in to Access.
