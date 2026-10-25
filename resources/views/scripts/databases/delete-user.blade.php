set -Eeuo pipefail

@if ($databaseUser->engine === App\Enums\DatabaseEngine::Postgres)
cd /
if sudo -u postgres psql -X -tA -c "SELECT 1 FROM pg_roles WHERE rolname = '{!! $databaseUser->username !!}'" | grep -q 1; then
    # A role that owns anything cannot be dropped, so its tables are handed to postgres instead of being lost.
    for database in $(sudo -u postgres psql -X -tA -c 'SELECT datname FROM pg_database WHERE datallowconn'); do
        sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 -d "$database" -c 'REASSIGN OWNED BY "{!! $databaseUser->username !!}" TO postgres; DROP OWNED BY "{!! $databaseUser->username !!}";'
    done

    sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 -c 'DROP ROLE IF EXISTS "{!! $databaseUser->username !!}";'
fi
@else
mysql -e "DROP USER IF EXISTS '{!! $databaseUser->username !!}'@'%';"
@endif
