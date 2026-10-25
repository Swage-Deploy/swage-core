set -Eeuo pipefail

@include('scripts.daemons.partials.config')

supervisorctl reread
supervisorctl update
supervisorctl restart '{!! $daemon->programName() !!}:*'
