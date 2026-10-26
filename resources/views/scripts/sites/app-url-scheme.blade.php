@php($from = $scheme === 'https' ? 'http' : 'https')
@php($hosts = collect($domains)->map(fn ($domain) => preg_quote($domain, '#'))->implode('|'))
# Point APP_URL at {!! $scheme !!}:// when it names one of the site's domains, leaving custom URLs alone.
ENV_FILE='{!! $site->path() !!}/shared/.env'
if sudo -u {!! $site->systemUser() !!} grep -qE '^APP_URL=["'"'"']?{!! $from !!}://({!! $hosts !!})(["'"'"'/:]|$)' "$ENV_FILE" 2> /dev/null; then
    sudo -u {!! $site->systemUser() !!} sed -i -E 's#^APP_URL=(["'"'"']?){!! $from !!}://({!! $hosts !!})(["'"'"'/:]|$)#APP_URL=\1{!! $scheme !!}://\2\3#' "$ENV_FILE"
    echo "Set APP_URL to {!! $scheme !!}://"

@include('scripts.sites.reload-config')
fi
