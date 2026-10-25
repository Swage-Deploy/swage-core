set -Eeuo pipefail

@if ($database->isSystemDatabase())
echo "{!! $database->name !!} is one of the database server's own databases." >&2
exit 1
@else
{{-- The dump is the script's only output, so nothing else may write to standard output. --}}
mysqldump --single-transaction --quick --routines --triggers --events --no-tablespaces --set-gtid-purged=OFF \
    --default-character-set=utf8mb4 '{!! $database->name !!}' | gzip -c
@endif
