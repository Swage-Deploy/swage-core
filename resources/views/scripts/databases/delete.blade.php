set -Eeuo pipefail

@if ($database->isSystemDatabase())
echo "{!! $database->name !!} is one of the database server's own databases and cannot be dropped." >&2
exit 1
@elseif ($database->engine === App\Enums\DatabaseEngine::Postgres)
cd /
sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 -c 'DROP DATABASE IF EXISTS "{!! $database->name !!}" WITH (FORCE);'
@else
mysql -e 'DROP DATABASE IF EXISTS `{!! $database->name !!}`;'
@endif
