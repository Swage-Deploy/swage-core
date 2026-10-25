set -Eeuo pipefail

install -d -m 750 -o root -g adm {!! App\Models\Daemon::LOG_DIRECTORY !!}
@foreach ($daemons as $daemon)

@include('scripts.daemons.partials.config', ['daemon' => $daemon])
@endforeach

supervisorctl reread
supervisorctl update

echo "Daemon logs moved to {!! App\Models\Daemon::LOG_DIRECTORY !!}."
