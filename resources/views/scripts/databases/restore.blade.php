set -Eeuo pipefail

trap "rm -f '{!! $path !!}'" EXIT

@if ($database->isSystemDatabase())
echo "{!! $database->name !!} is one of the database server's own databases and cannot be restored." >&2
exit 1
@else
read_file() {
@if ($compressed)
    gunzip -c '{!! $path !!}'
@else
    cat '{!! $path !!}'
@endif
}

# A dump of several databases, or of one under another name, would write over databases besides this one. grep reads
# the whole file rather than stopping at a match, which would fail the pipeline.
if read_file | grep -iE '^[[:space:]]*(USE|CREATE[[:space:]]+(DATABASE|SCHEMA))[[:space:]]' > /dev/null; then
    echo "The file creates or switches databases, so it could change databases besides {!! $database->name !!}. Export only the one database, without --databases or --all-databases, and try again." >&2
    exit 1
fi

echo "Replacing {!! $database->name !!} with an empty database."
mysql -e 'DROP DATABASE IF EXISTS `{!! $database->name !!}`; CREATE DATABASE `{!! $database->name !!}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'

echo "Importing a $(du -h '{!! $path !!}' | cut -f1) file."
# Views, triggers and routines name the account that defined them, which may not exist on this server, so they are
# defined by root instead.
read_file | sed -E 's/DEFINER=`[^`]*`@`[^`]*`//g' | mysql --default-character-set=utf8mb4 '{!! $database->name !!}'

echo "Restored {!! $database->name !!}."
@endif
