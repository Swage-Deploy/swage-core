set -Eeuo pipefail

export DEBIAN_FRONTEND=noninteractive

apt-get update -q
apt-get install -yq postgresql-{!! App\Enums\DatabaseEngine::POSTGRES_VERSION !!}-pgvector

# Databases Swage created before get the extension too, so every one of them can use the vector type.
cd /
for database in {!! $databases->map(fn ($database) => "'{$database->name}'")->implode(' ') !!}; do
    if sudo -u postgres psql -X -tA -c "SELECT 1 FROM pg_database WHERE datname = '$database'" | grep -q 1; then
        sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 -d "$database" -c 'CREATE EXTENSION IF NOT EXISTS vector;'
    fi
done

echo "pgvector is installed."
