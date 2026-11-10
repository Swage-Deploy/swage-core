# Source control

A site's repository comes from a **source control provider** or from a **custom git repository**.

## Providers

Providers come from [plugins](/docs/plugins):

| Provider | Plugin | Notes |
|---|---|---|
| **GitHub** | [`swage/github`](https://github.com/Swage-Deploy/swage-plugin-github) | Repositories your GitHub App is installed on, in your account and your organizations. |
| **GitLab** | [`swage/gitlab`](https://github.com/Swage-Deploy/swage-plugin-gitlab) | GitLab.com or your own GitLab, subgroups included. |

With a provider, Swage:

- lists your repositories and their branches when you install one on a site,
- adds a read-only deploy key to the repository, one for each site,
- creates a webhook when you turn on quick deploy, and verifies each delivery the way the provider signs them.

### Setting up a provider

1. Install its plugin from the **System** tab of the **Plugins** page.
2. On the same tab, open the provider's **Configure** page. It shows the callback URL to give the provider, in the form `https://your-domain/auth/<provider>/callback`.
3. Create an application with the provider using that callback URL, and enter its credentials on the config page. Settings are stored encrypted, and secrets are never shown again: leave one blank to keep it.
   - **GitHub:** create a **GitHub App** with only the **Administration** and **Webhooks** repository permissions (read and write), and install it on the repositories Swage should deploy. Use the permissions under **Repository permissions**: the ones with the same names under **Organization permissions** don't cover repositories. If you change the permissions later, accept the change on the app's installation before it applies. Its tokens then reach only those repositories, and cannot read or push code. An OAuth app also works, but it has to be given every private repository of whoever connects. See the [plugin's README](https://github.com/Swage-Deploy/swage-plugin-github#readme).
   - **GitLab:** create an application with the `api` scope. That scope reaches every project the connected account can, so consider connecting a dedicated account that is a Maintainer of only the projects Swage deploys. See the [plugin's README](https://github.com/Swage-Deploy/swage-plugin-gitlab#readme).

### Connecting your account

Each user connects their own accounts from **Source control** in the sidebar. A server's sites use its owner's connection, so only the server's owner can install a repository from a provider or turn quick deploy on and off. [Members](/docs/members) can install a custom git repository and deploy.

If connecting an account fails, the page says why, such as the provider refusing the request or the callback URL not matching, and the cause is written to the panel's log.

If a provider says a site's account is not connected, the account was disconnected or its token revoked. Reconnect it from **Source control**. Deployments keep working meanwhile: only installing repositories and changing quick deploy need the connection.

## Custom git repositories

A custom git repository works with any git host reachable over SSH, such as Gitea, Forgejo, Bitbucket or a bare repository on your own server, and needs no plugin.

1. On the site's page, choose **Custom git repository** as the source.
2. Enter the SSH clone URL, such as `git@git.example.com:team/app.git`, and the branch.
3. Swage generates a deploy key and shows it on the site's page. Add it to the repository as a **read-only** deploy key.
4. Deploy.

Quick deploy is not available for custom repositories. To deploy on push, have your git host or CI call the site's [deploy trigger URL](/docs/deployments#triggering-a-deployment).

## Removing a provider

Every site records the URL its repository is cloned from, so it keeps deploying even if its provider is disconnected or its plugin is removed.
