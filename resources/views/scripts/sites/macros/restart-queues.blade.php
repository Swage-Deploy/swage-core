@forelse ($site->daemons->reject->isOctane() as $daemon)
swage_restart_daemon {!! $daemon->id !!}
@empty
echo "No queue workers to restart"
@endforelse
