set -Eeuo pipefail

@php($grantedDatabases = $databaseUser->databases->reject->isSystemDatabase())
@if ($databaseUser->engine === App\Enums\DatabaseEngine::Postgres)
cd /
sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 <<'SWAGE_EOF'
DO $$
DECLARE
    other_database text;
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{!! $databaseUser->username !!}') THEN
        CREATE ROLE "{!! $databaseUser->username !!}";
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
mysql <<'SWAGE_EOF'
CREATE USER IF NOT EXISTS '{!! $databaseUser->username !!}'@'%' IDENTIFIED BY '{!! $databaseUser->password !!}';
ALTER USER '{!! $databaseUser->username !!}'@'%' IDENTIFIED BY '{!! $databaseUser->password !!}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '{!! $databaseUser->username !!}'@'%';
@foreach ($grantedDatabases as $database)
GRANT ALL PRIVILEGES ON `{!! $database->name !!}`.* TO '{!! $databaseUser->username !!}'@'%';
@endforeach
FLUSH PRIVILEGES;
SWAGE_EOF
@endif
