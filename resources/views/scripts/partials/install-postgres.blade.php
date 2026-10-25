apt-get install -yq postgresql{!! $server->has_pgvector ? ' postgresql-'.App\Enums\DatabaseEngine::POSTGRES_VERSION.'-pgvector' : '' !!}
systemctl enable --now postgresql

# Local socket logins map to the matching system user; the "{!! $user !!}" role is for administrators.
cd /
sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 <<'SWAGE_EOF'
DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{!! $user !!}') THEN
        CREATE ROLE "{!! $user !!}";
    END IF;
END
$$;
ALTER ROLE "{!! $user !!}" WITH LOGIN SUPERUSER PASSWORD '{!! $server->database_password !!}';
SWAGE_EOF
