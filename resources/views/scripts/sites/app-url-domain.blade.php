# Point APP_URL at {!! $to !!} when it names {!! $from !!}, leaving custom URLs alone.
ENV_FILE='{!! $site->path() !!}/shared/.env'
if sudo -u {!! $site->systemUser() !!} grep -qE '^APP_URL=["'"'"']?https?://{!! preg_quote($from, '#') !!}(["'"'"'/:]|$)' "$ENV_FILE" 2> /dev/null; then
    sudo -u {!! $site->systemUser() !!} sed -i -E 's#^APP_URL=(["'"'"']?)(https?)://{!! preg_quote($from, '#') !!}(["'"'"'/:]|$)#APP_URL=\1\2://{!! $to !!}\3#' "$ENV_FILE"
    echo "Set APP_URL to {!! $to !!}"

@include('scripts.sites.reload-config')
fi
