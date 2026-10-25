set -Eeuo pipefail

mysql <<'SWAGE_EOF'
ALTER USER IF EXISTS '{!! $user !!}'@'%' IDENTIFIED BY '{!! $password !!}';
FLUSH PRIVILEGES;
SWAGE_EOF

echo "Changed the password of the {!! $user !!} MySQL account."
