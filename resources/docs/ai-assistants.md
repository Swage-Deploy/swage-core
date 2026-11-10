# AI assistants

Swage has a read-only [MCP](https://modelcontextprotocol.io) server, so Claude Code, Claude Desktop, Cursor and other AI assistants can look at your panel and help you work out what's wrong. An assistant can answer questions such as "why did the last deploy of api.example.com fail?" or "which server is running out of disk?", and explain how to fix it using these docs.

It can only read. It has no way to deploy, restart, reboot, change settings or run commands, and it never connects to your servers. It reads what Swage has already recorded.

## Connecting an assistant

1. Under **Settings → API keys**, choose **New API key**, then **AI assistant**. This gives the key read access to servers, sites, deployments and certificates.
2. Choose **Create key**. Swage shows the key once, along with a command that adds the MCP server to Claude Code:

   ```bash
   claude mcp add --transport http swage https://swage.example.com/mcp --header 'Authorization: Bearer <your key>'
   ```

Other assistants connect to the same address, `https://<your panel>/mcp`, over HTTP with the key sent as a bearer token in the `Authorization` header.

The MCP server only accepts keys that can read and nothing else. A key with write access to any area, or that can run commands as root, is refused, so a key that can deploy is never handed to an assistant by mistake.

## What an assistant can see

The key acts as you, so an assistant sees only the servers and sites you can. Each area the key can read adds tools:

| Key can read | The assistant can |
|---|---|
| **Servers** | List servers and read their details, CPU, memory, disk and load over the past week, whether they need a reboot, and their activity log with each task's output. |
| **Sites** | List sites and read their settings and repository. |
| **Deployments** | List a site's deployments and read each one's output and the deploy script. |
| **Certificates** | See when certificates expire and which are failing to renew. |

With any key, an assistant can also read your notifications, without marking them read, and search and read this documentation. The documentation comes bundled with Swage, so it always matches the version you run.

An assistant never sees `.env` files, database passwords, deploy keys, deploy trigger URLs, webhook URLs, provisioning commands or the email addresses of other users, even if the key can read the **Environment** area. Live logs aren't available either, since reading them means running a command on the server; read them on the server's **Logs** tab.

## Asking for help

Some assistants offer the server's **troubleshoot** prompt, which takes a description of the problem. It has the assistant find the server or site involved, read what Swage recorded and the relevant docs, then explain the cause and the steps for you to take.

Deployment and task output comes from your servers and repositories. Swage tells assistants to treat it as information rather than instructions, but review what an assistant suggests before acting on it.

## Revoking access

To disconnect an assistant, **Revoke** its key under **Settings → API keys**. It stops working straight away. Keys also stop working when they expire.
