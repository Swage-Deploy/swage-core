set -Eeuo pipefail

@if ($database->isSystemDatabase())
echo "{!! $database->name !!} is one of the database server's own databases." >&2
exit 1
@elseif ($database->engine === App\Enums\DatabaseEngine::Postgres)
cd /
if ! sudo -u postgres psql -X -tA -c "SELECT 1 FROM pg_database WHERE datname = '{!! $database->name !!}'" | grep -q 1; then
    sudo -u postgres createdb --encoding=UTF8 --template=template0 '{!! $database->name !!}'
fi

# Every role may connect to a new database until this is revoked; only the users given it may.
sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 -c 'REVOKE ALL ON DATABASE "{!! $database->name !!}" FROM PUBLIC;'
@if ($database->server->has_pgvector)

# pgvector is not a trusted extension, so only a superuser can enable it.
sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 -d '{!! $database->name !!}' -c 'CREATE EXTENSION IF NOT EXISTS vector;'
@endif
@else
mysql -e 'CREATE DATABASE IF NOT EXISTS `{!! $database->name !!}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
@endif
