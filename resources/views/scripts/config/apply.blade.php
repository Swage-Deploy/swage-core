set -Eeuo pipefail

# Writes new config files, checks them and reloads the service. Any failure puts the previous files back.

BACKUP=$(mktemp -d)
trap 'rm -rf "$BACKUP"' EXIT

@foreach (array_keys($files) as $index => $path)
if [ -e {!! escapeshellarg($path) !!} ]; then cp -p {!! escapeshellarg($path) !!} "$BACKUP/{!! $index !!}"; fi
@endforeach

restore() {
    echo "Restoring the previous configuration."
@foreach (array_keys($files) as $index => $path)
    if [ -e "$BACKUP/{!! $index !!}" ]; then cp -p "$BACKUP/{!! $index !!}" {!! escapeshellarg($path) !!}; else rm -f {!! escapeshellarg($path) !!}; fi
@endforeach
}

reload() {
@forelse ($reload as $command)
    {!! $command !!}
@empty
    true
@endforelse
}

# Runs a command and fails if it does, or if it prints a warning.
check_clean() {
    local output
    output=$("$@" 2>&1) || { echo "$output"; return 1; }
    echo "$output"
    ! grep -qiE 'warning|syntax error|unable to|unknown' <<< "$output"
}

@include('scripts.partials.write-files', ['files' => $files])

@foreach ($validate as $command)
if ! {!! $command !!}; then
    echo "Validation failed: {!! str_replace(['"', '$', '`', '\\'], '', $command) !!}"
    restore
    exit 1
fi
@endforeach

if ! reload; then
    echo "Reloading the service failed."
    restore
    reload || true
    exit 1
fi

@if ($healthChecks !== [])
sleep 2

@foreach ($healthChecks as $command)
if ! {!! $command !!}; then
    echo "Health check failed: {!! str_replace(['"', '$', '`', '\\'], '', $command) !!}"
    restore
    reload || true
    exit 1
fi
@endforeach
@endif

echo "Configuration applied."
