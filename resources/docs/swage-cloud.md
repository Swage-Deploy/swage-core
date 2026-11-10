# Swage Cloud

Swage Cloud runs a Swage panel for you, so you don't need a server of your own for it. It's the same Swage you would [install](/docs/installation) yourself, on your own domain, and it manages your servers the same way.

## What you get

- One Swage panel per subscription, with 2 vCPUs, 2 GB of memory and 20 GB of disk.
- A dedicated IPv4 address, which your servers see Swage connect from.
- A Let's Encrypt certificate for your domain, renewed for you.
- [Automatic updates](/docs/updating#automatic-updates). Your panel updates Swage and then its plugins every day, so there are no update buttons to press.
- [Plugins](/docs/plugins) from the plugin index. Installing a plugin from your own git repository is turned off on hosted panels.

You manage your panel from its own pages, as usual. Swage Cloud only looks after the server it runs on.

## Getting started

1. [Create an account](/register) and confirm your email address. That address becomes the administrator of your panel.
2. Choose a monthly or yearly plan and pay through Stripe.
3. Enter the domain your panel will be served from, such as `swage.example.com`.
4. Add the **A record** Swage Cloud shows you at your DNS provider, then press **Continue**. Swage Cloud checks the record before it starts.
5. Wait while your panel is set up. It usually takes 10 to 15 minutes, and you get an email when it's ready.
6. Press **Reveal password** on your dashboard and store the password in your password manager. It's shown **once** and then removed from Swage Cloud.
7. Log in to your panel and set up two-factor authentication, which Swage asks for the first time.

### DNS

The domain needs an A record pointing straight at your panel's address:

- Remove any **AAAA** record for the domain. Let's Encrypt checks IPv6 first, and the certificate would fail.
- If your DNS is on Cloudflare, set the record to **DNS only** (grey cloud). Swage Cloud panels serve HTTPS themselves.

## Changing the domain

Press **Change domain** on your dashboard, enter the new domain and add the A record it shows. It points at the same address. Press **Continue** and the panel moves over, as described in [Changing the domain](/docs/changing-domain). Quick deploy webhooks move with it, but update your GitHub or GitLab OAuth app's callback URL and any CI jobs that call a deployment trigger URL yourself.

## Billing

**Billing** in the menu opens Stripe's billing portal, where you can switch between monthly and yearly, update your card, download invoices or cancel.

When you cancel, your panel keeps running until the end of the period you've paid for.

## When a subscription ends

When a subscription ends, by cancelling or because a payment fails for good, the panel is stopped. The servers it manages and the sites on them keep running, but nothing deploys and no one can log in to the panel.

The stopped panel is kept for 14 days. Resubscribe from your dashboard within that time and it starts again as it was. After that, the panel and its data are deleted, and this can't be undone.
