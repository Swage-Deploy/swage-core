# Server and site members

Each server belongs to the user who created it, its **owner**. To share it, add other people as **members** of the whole server, or of just one of its sites.

## Roles

| Role | Can |
|---|---|
| **Manager** | Deploy, change settings and the environment, run Artisan commands, export and restore the server's databases, and add or remove members. Not run daemons or scheduled jobs as root, or change the server's service config. |
| **Viewer** | See deployments, settings and logs, but change nothing. The site's environment, which holds its secrets, and its **Commands** tab stay hidden, and they cannot export databases. |

## Server members

Open the server's **Members** tab, enter the email address of someone's Swage account, pick a role and add them. Server members can reach the server and every site on it.

To give someone just one site, add them on that site's **Members** tab instead.

## Site members

A site's **Members** tab lists people with access to just that site. They reach its pages without seeing the rest of the server, and the site appears on their dashboard on its own. The server's owner and members can reach the site too.

## Adding people

You can add anyone who already has a Swage account. If they do not have one yet, an administrator can [add them on the Users page](/docs/users#adding-a-user).

Change a member's role or remove them from the same tab. A server's owner, its Managers and any administrator can manage the server's members. A site's Managers can manage its members too.

## What only the owner can do

Some actions stay with the server's owner, whatever role others have:

- **Deleting the server.**
- **Running daemons and scheduled jobs as `root`**, and **changing the server's [configuration overrides](/docs/configuration-overrides)**, which its services read as root. Either controls the whole server, so Managers can do neither.
- **Installing a repository from a connected provider**, such as GitHub, and **turning quick deploy on or off**. Swage acts through the owner's connected account, so members never reach the owner's repositories. Members can still install a [custom git repository](/docs/source-control#custom-git-repositories) and deploy.

Deleting a site, or changing its [custom nginx](/docs/site-settings#nginx), needs someone who can manage its server, not just the site. nginx reads a site's custom config as root, so it could reach the rest of the server.
