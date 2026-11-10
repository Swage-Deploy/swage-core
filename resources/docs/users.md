# Users

Everyone who signs in to Swage has a user account. Administrators manage them on the **Users** page, after confirming their password.

## Who can do what

Two separate settings decide what someone can do:

- **Their account type**, administrator or user. It is set on this page and applies across the whole panel.
- **Their access to each server or site**, as its owner, a Manager or a Viewer. It is set on each server's or site's **Members** tab, so the same person can manage one server, only view another, and not see a third at all.

An account type is never a server role, or the other way round. A user can be the owner or a Manager of servers, and an administrator who is neither cannot deploy to a server.

### Administrators and users

| | Administrators | Users |
|---|---|---|
| **Updates, plugins and users** | Manage them | Cannot see them |
| **Sign in as someone else** | Yes, any user who is not an administrator | No |
| **Every server and site** | See them, manage their members and export or restore their databases | Only see the ones they own or are a member of |

The account the installer creates is an administrator. To see Swage the way someone else does, [sign in as them](#signing-in-as-a-user).

### Owners, Managers and Viewers

| Access | Who has it | Can |
|---|---|---|
| **Owner** | Whoever created the server | Everything, including deleting the server, running commands as root, changing its service config and installing repositories through their connected source control account. |
| **Manager** | People added to the server or site as a Manager | Deploy, change settings and the environment, run Artisan commands, export and restore databases, and add or remove members. |
| **Viewer** | People added to the server or site as a Viewer | See deployments, settings and logs, but change nothing. |

Both administrators and users get this access the same way. See [Server and site members](/docs/members).

[API keys](/docs/api#api-keys) act as the user who created them, with the same access, so they never reach more than that user can.

## Adding a user

Choose **Add user** and enter their name and email address. Turn on **Administrator** to let them manage updates, plugins and users.

Swage generates the user's password and shows it **once**, for you to pass on. The email address is marked as verified, because you vouch for it. Unless [two-factor authentication is optional](/docs/configuration), the user sets it up the first time they sign in.

> **Tip:** If people should create their own accounts instead, set `SWAGE_REGISTRATION=true` to open `/register`. Accounts created there are never administrators.

## Managing a user

Each user's **Manage** menu can:

- **Edit** their name, email address and whether they are an administrator,
- **Reset password**, which generates a new one, shows it once, stops the old one working, signs them out everywhere and revokes their [API keys](/docs/api#api-keys),
- **Reset two-factor**, which turns off their two-factor authentication so they can set it up again the next time they sign in, such as after losing their phone,
- **Sign in as** them,
- **Remove** them, so they can no longer sign in.

You cannot remove a user who still owns servers. Delete their servers first.

You manage your own account from **Settings**, not from the Users page.

## Signing in as a user

**Sign in as** shows Swage the way that user sees it, which helps you check what they can reach or follow along with a problem they report. A banner stays at the top of every page while you are signed in as them, and **Stop impersonating** signs you back in as yourself.

While you are signed in as someone, you cannot use their account settings: profile, password, two-factor authentication, passkeys and connected source control accounts. Every time you start or stop, it is recorded in the panel's log.

You can sign in as any user except other administrators and users who have not verified their email address.
