{{-- Each line arrives base64 encoded and is matched with awk, so no value is ever parsed by the shell or sed. --}}
{{-- The site's user reads and writes its own .env, so a symlink planted in its place cannot point root at another file. --}}
swage_env_read() {
    sudo -u {!! $site->systemUser() !!} cat "$ENV_FILE"
}

swage_env_write() {
    local tmp
    tmp=$(mktemp)
    cat > "$tmp"
    sudo -u {!! $site->systemUser() !!} sh -c 'umask 077 && cat > "$1"' sh "$ENV_FILE" < "$tmp"
    rm -f "$tmp"
}

swage_env_set() {
    swage_env_read | SWAGE_LINE=$(printf '%s' "$2" | base64 -d) awk -v key="$1=" '
        index($0, key) == 1 { if (!done) print ENVIRON["SWAGE_LINE"]; done = 1; next }
        { print }
        END { if (!done) print ENVIRON["SWAGE_LINE"] }
    ' | swage_env_write
}

swage_env_unset() {
    swage_env_read | awk -v key="$1=" 'index($0, key) != 1' | swage_env_write
}

@foreach ($values as $key => $value)
@if ($value === null)
swage_env_unset {!! App\Sites\SiteEnvironment::key($key) !!}
@else
swage_env_set {!! App\Sites\SiteEnvironment::key($key) !!} {!! escapeshellarg(base64_encode(App\Sites\SiteEnvironment::line($key, $value))) !!}
@endif
@endforeach
