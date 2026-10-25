set -Eeuo pipefail

ENV_FILE='{!! $site->path() !!}/shared/.env'

if [ ! -f "$ENV_FILE" ]; then
    echo "$ENV_FILE does not exist. Install the site first." >&2
    exit 1
fi
@if ($setup !== '')

{!! $setup !!}
@endif

@include('scripts.sites.partials.set-env-values')
echo "Updated {!! implode(', ', array_keys($values)) !!} in the environment"

@include('scripts.sites.reload-config')
@foreach ($site->daemons->reject->isOctane() as $daemon)
supervisorctl restart '{!! $daemon->programName() !!}:*' || echo "Warning: {!! $daemon->programName() !!} could not be restarted."
@endforeach
