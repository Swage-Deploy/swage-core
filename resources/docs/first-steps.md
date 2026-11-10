# First steps

With the panel installed, log in, secure your account and connect a git host, then you are ready to connect servers.

## Log in

Open `https://your-domain/login` and log in with the administrator email you gave the installer and the password it printed.

## Set up two-factor authentication

Swage asks every user to set up two-factor authentication before anything else. Scan the QR code with an authenticator app, enter a code to confirm it, and store the recovery codes somewhere safe.

You can also add **passkeys** under **Settings → Security**, to log in with Touch ID, Windows Hello or a security key.

To make two-factor authentication optional, set `SWAGE_REQUIRE_TWO_FACTOR=false`. See [Configuration](/docs/configuration).

## Connect GitHub

Source control providers come from plugins. To deploy from GitHub:

1. Open **Plugins** and, on the **System** tab, install **GitHub** from the **Available** list. The panel rebuilds in the background, which takes a minute.
2. Still on the **System** tab, choose **Configure GitHub**. The page shows a callback URL.
3. On GitHub, under **Settings → Developer settings → OAuth Apps**, create an OAuth app. Use the panel's URL as the homepage URL and the callback URL from the config page as the **Authorization callback URL**.
4. Enter the app's **client ID** and a **client secret** on the config page and save.
5. Open **Source control** in the sidebar and connect your GitHub account.

GitLab works the same way through its plugin. For other git hosts, no plugin is needed: see [Source control](/docs/source-control).

## Add more people

Registration is turned off by default. Add accounts for other people on the **Users** page, where Swage generates each password and shows it once. See [Users](/docs/users).

Each user sees only the servers they own, plus any they are added to. To share a server or a single site, add people as [members](/docs/members), as Managers or Viewers.

## Connect your first server

You are ready to [connect a server](/docs/servers).
