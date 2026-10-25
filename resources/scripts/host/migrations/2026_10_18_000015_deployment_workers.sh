# Deployments get workers of their own, so up to two long deployments can run
# at once without holding up site installs, certificates and other tasks.

cat > /etc/supervisor/conf.d/swage-worker.conf <<CONF
[program:swage-worker]
command=php $APP_ROOT/current/artisan queue:work redis --queue=default --sleep=3 --tries=1 --max-time=3600
user=$APP_USER
process_name=%(program_name)s_%(process_num)02d
numprocs=3
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=1900
redirect_stderr=true
stdout_logfile=$APP_ROOT/shared/storage/logs/worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3

[program:swage-deployer]
command=php $APP_ROOT/current/artisan queue:work redis --queue=deployments --sleep=3 --tries=1 --max-time=3600
user=$APP_USER
process_name=%(program_name)s_%(process_num)02d
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=1900
redirect_stderr=true
stdout_logfile=$APP_ROOT/shared/storage/logs/deployer.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
CONF

supervisorctl reread
supervisorctl update

# Send deployments to the new workers. A fresh install has no .env yet, and its
# installer sets this itself. The app user edits its own file.
ENV_FILE="$APP_ROOT/shared/.env"

if [ -f "$ENV_FILE" ]; then
    as_app sh -c 'grep -v "^SWAGE_DEPLOYMENT_QUEUE=" "$1" > "$1.tmp" || true; echo "SWAGE_DEPLOYMENT_QUEUE=deployments" >> "$1.tmp"; cat "$1.tmp" > "$1"; rm -f "$1.tmp"' _ "$ENV_FILE"
fi
