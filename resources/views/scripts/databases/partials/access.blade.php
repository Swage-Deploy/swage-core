# Database ports are only open to the addresses allowed on the server's Databases page.
while rule=$(ufw status numbered | grep -m1 '# swage-database' | sed -E 's/^\[ *([0-9]+)\].*/\1/') && [ -n "$rule" ]; do
    ufw --force delete "$rule" > /dev/null
done
@foreach ($server->databaseAccess() as $address)
@foreach ($server->databaseEngines() as $engine)
ufw allow from {!! $address !!} to any port {!! $engine->port() !!} proto tcp comment 'swage-database'
@endforeach
@endforeach
@if ($server->usesPostgres())
@php($postgresDirectory = '/etc/postgresql/'.App\Enums\DatabaseEngine::POSTGRES_VERSION.'/main')

# PostgreSQL checks the address as well, and only accepts passwords from the allowed ones.
cat > {!! $postgresDirectory !!}/swage-hba.conf <<'SWAGE_EOF'
@foreach ($server->databaseAccess() as $address)
host all all {!! str_contains($address, '/') ? $address : $address.(str_contains($address, ':') ? '/128' : '/32') !!} scram-sha-256
@endforeach
SWAGE_EOF
grep -qxF 'include_if_exists swage-hba.conf' {!! $postgresDirectory !!}/pg_hba.conf || echo 'include_if_exists swage-hba.conf' >> {!! $postgresDirectory !!}/pg_hba.conf
systemctl reload postgresql
@endif
