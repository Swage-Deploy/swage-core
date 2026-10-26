set -Eeuo pipefail

@if ($database->isSystemDatabase())
rm -f '{!! $path !!}'
echo "{!! $database->name !!} is one of the database server's own databases and cannot be restored." >&2
exit 1
@else
@php($owner = "'".$database->ownerAccount()."'@'localhost'")
OWNER_CONFIG=$(mktemp)

# The dump is deleted, and the database's owner account locked again, however the restore ends.
swage_cleanup() {
    rm -f '{!! $path !!}' "$OWNER_CONFIG"
    mysql -e "ALTER USER IF EXISTS {!! $owner !!} ACCOUNT LOCK;" || true
}
trap swage_cleanup EXIT

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

# The dump is imported as the database's owner, which can only reach this database, rather than as root.
mysql <<'SWAGE_SQL'
CREATE USER IF NOT EXISTS {!! $owner !!} IDENTIFIED BY '{!! $ownerPassword !!}' ACCOUNT LOCK;
ALTER USER {!! $owner !!} IDENTIFIED BY '{!! $ownerPassword !!}' ACCOUNT UNLOCK;
GRANT ALL PRIVILEGES ON `{!! $database->grantName() !!}`.* TO {!! $owner !!};
SWAGE_SQL

cat > "$OWNER_CONFIG" <<'SWAGE_CNF'
[client]
user={!! $database->ownerAccount() !!}
password={!! $ownerPassword !!}
SWAGE_CNF

echo "Importing a $(du -h '{!! $path !!}' | cut -f1) file."
# Views, triggers and routines name the account that defined them, which may not exist on this server, so they are
# defined by the owner instead. Lines only a server administrator may run, such as turning off the binary log, are
# left out. --binary-mode keeps the mysql client from running commands in the file, such as \! or connect.
read_file \
    | sed -E -e 's/DEFINER=`[^`]*`@`[^`]*`//g' -e '/^SET @@(SESSION\.SQL_LOG_BIN|GLOBAL\.GTID_PURGED)[[:space:]]*=/d' \
    | mysql --defaults-extra-file="$OWNER_CONFIG" --binary-mode --default-character-set=utf8mb4 '{!! $database->name !!}'

echo "Restored {!! $database->name !!}."
@endif
