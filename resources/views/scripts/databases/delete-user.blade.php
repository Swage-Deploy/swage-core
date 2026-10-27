set -Eeuo pipefail

@php($marker = App\Models\DatabaseUser::ACCOUNT_COMMENT)
{{-- Only an account Swage created is dropped, never one that only shares its name. --}}
@if ($databaseUser->engine === App\Enums\DatabaseEngine::Postgres)
cd /
if [ "$(sudo -u postgres psql -X -tA -c "SELECT shobj_description(oid, 'pg_authid') FROM pg_roles WHERE rolname = '{!! $databaseUser->username !!}'")" = '{!! $marker !!}' ]; then
    # A role that owns anything cannot be dropped, so its tables are handed to postgres instead of being lost.
    for database in $(sudo -u postgres psql -X -tA -c 'SELECT datname FROM pg_database WHERE datallowconn'); do
        sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 -d "$database" -c 'REASSIGN OWNED BY "{!! $databaseUser->username !!}" TO postgres; DROP OWNED BY "{!! $databaseUser->username !!}";'
    done

    sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 -c 'DROP ROLE IF EXISTS "{!! $databaseUser->username !!}";'
fi
@else
ACCOUNT=$(mysql -N -B <<'SWAGE_EOF'
SELECT IFNULL(JSON_UNQUOTE(JSON_EXTRACT(User_attributes, '$.metadata.comment')), '') FROM mysql.user WHERE User = '{!! $databaseUser->username !!}' AND Host = '%';
SWAGE_EOF
)

if [ "$ACCOUNT" = '{!! $marker !!}' ]; then
    mysql -e "DROP USER IF EXISTS '{!! $databaseUser->username !!}'@'%';"
fi
@endif
