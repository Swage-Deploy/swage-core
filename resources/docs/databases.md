# Databases

Each server can run **MySQL 8**, **PostgreSQL 16**, or both, and PostgreSQL can have the **pgvector** extension for vector embeddings. Databases and their users are managed on the server's **Databases** page.

## Creating databases

On the **Databases** page, choose **New database**, pick the engine if the server runs both, and enter a name. You can also create a database and user for a site at the same time as the site.

## Database users

Create users on the same page and choose which databases each one can reach. A user only has access to the databases it is given, so one compromised site cannot read another's data.

Passwords are stored encrypted in the panel. Put them in each site's environment from the site's **Environment** page.

> **Note:** Users are per engine. A MySQL user and a PostgreSQL user with the same name are two different users.

## Connecting from a site

Sites on the same server connect over localhost:

```dotenv
# MySQL
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=shop
DB_USERNAME=shop
DB_PASSWORD=...

# PostgreSQL
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=shop
DB_USERNAME=shop
DB_PASSWORD=...
```

When you create a database while creating a site, Swage writes these values into the site's `.env` for you.

## Exporting and restoring

MySQL databases can be exported and restored from the **Databases** page:

- **Export** downloads a gzipped dump of the database, named after it and the time, such as `shop-2026-10-25-141502.sql.gz`. It streams from the server as it is made, so large databases start downloading straight away.
- **Restore from a file** replaces the database with a `.sql` or `.sql.gz` file of that one database, up to 1 GB, such as an export from here or from `mysqldump`. Every table in the database is dropped first, so export it beforehand if you may want its data back. Type the database's name to confirm.

A file is imported as an account that can only reach that one database, and the `mysql` client's own commands in it, such as `\!` or `connect`, are not run. So a dump cannot change other databases or run commands on the server, and its views, triggers and routines run with access to that database alone. Statements that need a server administrator are refused. Lines that turn off the binary log or set GTIDs are left out, but a stored function that is not declared `DETERMINISTIC`, `NO SQL` or `READS SQL DATA` cannot be created while the server keeps a binary log.

Both are recorded on the server's **Activity** page. An export holds all of a database's data, so only the server's owner, its Managers and administrators can export or restore. PostgreSQL databases cannot be exported or restored from the panel yet.

## Adding an engine later

The engine chosen when the server was connected is not final. From the **Databases** panel on the server's overview page you can install:

- MySQL on a server that runs PostgreSQL, or the other way round,
- pgvector on a server that runs PostgreSQL.

Swage then re-tunes every service so the engines share memory. This restarts PHP-FPM, the databases and Redis, which briefly interrupts requests.

## pgvector

With pgvector installed, enable it in each database that uses it, such as from a migration:

```php
DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
```

## Separate database servers

To give the database a machine of its own, see [Database servers](/docs/database-servers).
