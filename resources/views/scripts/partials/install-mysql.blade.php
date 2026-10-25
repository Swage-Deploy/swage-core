apt-get install -yq mysql-server

# Root authenticates over the local socket; the "{!! $user !!}" account is for applications.
mysql <<'SWAGE_EOF'
CREATE USER IF NOT EXISTS '{!! $user !!}'@'%' IDENTIFIED BY '{!! $server->database_password !!}';
GRANT ALL PRIVILEGES ON *.* TO '{!! $user !!}'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SWAGE_EOF
