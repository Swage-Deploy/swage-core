set -Eeuo pipefail

export DEBIAN_FRONTEND=noninteractive

apt-get update -q

@if ($engine === App\Enums\DatabaseEngine::Mysql)
@include('scripts.partials.install-mysql')
@else
@include('scripts.partials.install-postgres')
@endif

echo "{!! $engine->label() !!} is installed. Its memory is tuned next."
