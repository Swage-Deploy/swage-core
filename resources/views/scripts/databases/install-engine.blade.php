set -Eeuo pipefail

export DEBIAN_FRONTEND=noninteractive

apt-get update -q

@if ($engine === App\Enums\DatabaseEngine::Mysql)
@include('scripts.partials.install-mysql')
@else
@if ($server->php_versions->isNotEmpty())
# Servers provisioned before PostgreSQL support have no PHP driver for it.
apt-get install -yq {!! $server->php_versions->map(fn ($php) => "php{$php->value}-pgsql")->implode(' ') !!}
@endif

@include('scripts.partials.install-postgres')
@endif

echo "{!! $engine->label() !!} is installed. Its memory is tuned next."
