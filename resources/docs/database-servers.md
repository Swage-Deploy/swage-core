# Database servers

A database server runs only MySQL or PostgreSQL, for app servers to connect to. It gets no nginx, PHP, Node.js or Redis, and its databases are tuned to use the memory PHP would otherwise have had.

## Creating one

Choose **New server**, pick **Database server**, and choose MySQL or PostgreSQL. You can add the other engine, or pgvector, later from its overview page, the same way as on an app server.

Give it a **private IP address** if your provider has a private network. Database connections are not encrypted, so they should travel over a private network wherever possible.

## Where it listens

- With a private IP address, the databases listen on it only.
- Without one, they listen on every interface, and only the firewall stands between them and the internet.

Either way, the firewall closes the database ports to everyone until you allow them.

## Remote access

On the server's **Databases** page, under **Remote access**, add the addresses that may connect:

- a single IP address, such as `10.0.0.12`,
- a range, such as `10.0.0.0/24`,
- or one of your other servers, picked from a list. Swage uses its private address when it has one.

Swage opens the MySQL and PostgreSQL ports to exactly those addresses in the firewall. PostgreSQL also only accepts password logins from them. The page shows the address and ports to connect to.

## Connecting an app server's site

1. Create the database and a user on the database server.
2. Add the app server to **Remote access**.
3. On the site's **Environment** page, point the site at the database server:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=10.0.0.5
DB_PORT=5432
DB_DATABASE=shop
DB_USERNAME=shop
DB_PASSWORD=...
```

4. Deploy, or turn on **Run config:clear after saving**, so the app reads the new values.
