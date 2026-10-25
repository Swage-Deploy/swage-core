# MySQL, listening on localhost only, with Swage's database and user.

apt-get install -yq mysql-server

cat > /etc/mysql/mysql.conf.d/99-swage.cnf <<CNF
[mysqld]
bind-address = 127.0.0.1
mysqlx-bind-address = 127.0.0.1
local_infile = 0
skip-name-resolve
CNF

systemctl restart mysql

DB_PASSWORD=$(swage_secret DB_PASSWORD)

mysql <<SQL
DELETE FROM mysql.user WHERE User = '';
DROP DATABASE IF EXISTS test;
CREATE DATABASE IF NOT EXISTS swage CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'swage'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER 'swage'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON swage.* TO 'swage'@'localhost';
FLUSH PRIVILEGES;
SQL
