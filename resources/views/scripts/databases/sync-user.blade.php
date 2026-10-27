set -Eeuo pipefail

@php($grantedDatabases = $databaseUser->databases->reject->isSystemDatabase())
@php($marker = App\Models\DatabaseUser::ACCOUNT_COMMENT)
{{-- Only an account Swage created is changed. Any other, such as one an admin added by hand, keeps its password and access. --}}
@if ($databaseUser->engine === App\Enums\DatabaseEngine::Postgres)
cd /
sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 <<'SWAGE_EOF'
DO $$
DECLARE
    other_database text;
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{!! $databaseUser->username !!}') THEN
        CREATE ROLE "{!! $databaseUser->username !!}";
        COMMENT ON ROLE "{!! $databaseUser->username !!}" IS '{!! $marker !!}';
    ELSIF shobj_description((SELECT oid FROM pg_roles WHERE rolname = '{!! $databaseUser->username !!}'), 'pg_authid') IS DISTINCT FROM '{!! $marker !!}' THEN
        RAISE EXCEPTION 'The role {!! $databaseUser->username !!} already exists and was not created by Swage. Choose another name.';
    END IF;

    -- Access to every database the user is no longer given is taken away.
    FOR other_database IN
        SELECT datname FROM pg_database
        WHERE NOT datistemplate AND datname <> ALL (ARRAY[{!! $grantedDatabases->map(fn ($database) => "'{$database->name}'")->implode(', ') !!}]::text[])
    LOOP
        EXECUTE format('REVOKE ALL ON DATABASE %I FROM %I', other_database, '{!! $databaseUser->username !!}');
    END LOOP;
END
$$;
ALTER ROLE "{!! $databaseUser->username !!}" WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD '{!! $databaseUser->password !!}';
@foreach ($grantedDatabases as $database)

GRANT ALL PRIVILEGES ON DATABASE "{!! $database->name !!}" TO "{!! $databaseUser->username !!}";
\connect "{!! $database->name !!}"
GRANT ALL ON SCHEMA public TO "{!! $databaseUser->username !!}";
GRANT ALL ON ALL TABLES IN SCHEMA public TO "{!! $databaseUser->username !!}";
GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO "{!! $databaseUser->username !!}";
\connect postgres
@endforeach
SWAGE_EOF
@else
ACCOUNT=$(mysql -N -B <<'SWAGE_EOF'
SELECT CONCAT('found:', IFNULL(JSON_UNQUOTE(JSON_EXTRACT(User_attributes, '$.metadata.comment')), '')) FROM mysql.user WHERE User = '{!! $databaseUser->username !!}' AND Host = '%';
SWAGE_EOF
)

if [ -n "$ACCOUNT" ] && [ "$ACCOUNT" != 'found:{!! $marker !!}' ]; then
    echo "The user {!! $databaseUser->username !!} already exists and was not created by Swage. Choose another name." >&2
    exit 1
fi

mysql <<'SWAGE_EOF'
CREATE USER IF NOT EXISTS '{!! $databaseUser->username !!}'@'%' IDENTIFIED BY '{!! $databaseUser->password !!}' COMMENT '{!! $marker !!}';
ALTER USER '{!! $databaseUser->username !!}'@'%' IDENTIFIED BY '{!! $databaseUser->password !!}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '{!! $databaseUser->username !!}'@'%';
@foreach ($grantedDatabases as $database)
{{-- The escaped name keeps the grant from also matching databases whose names differ where this one has _. --}}
GRANT ALL PRIVILEGES ON `{!! $database->grantName() !!}`.* TO '{!! $databaseUser->username !!}'@'%';
@endforeach
FLUSH PRIVILEGES;
SWAGE_EOF
@endif
