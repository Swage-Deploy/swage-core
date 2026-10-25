# Laravel connects to MySQL over TCP at 127.0.0.1. With skip-name-resolve on
# (000004), MySQL no longer maps that address to 'localhost', so the
# application user also needs an account for 127.0.0.1.

DB_PASSWORD=$(swage_secret DB_PASSWORD)

mysql <<SQL
CREATE USER IF NOT EXISTS 'swage'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER 'swage'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON swage.* TO 'swage'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
