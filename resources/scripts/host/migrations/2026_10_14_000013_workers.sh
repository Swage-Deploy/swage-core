# Queue workers and the scheduler. Deployments can run for 30 minutes, so
# workers are given time to finish their job before being stopped.

# Supervisor refuses a program whose log directory is missing, and a fresh
# install only creates the first release after its host migrations.
as_app mkdir -p "$APP_ROOT/shared/storage/logs"

cat > /etc/supervisor/conf.d/swage-worker.conf <<CONF
[program:swage-worker]
command=php $APP_ROOT/current/artisan queue:work redis --sleep=3 --tries=1 --max-time=3600
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
CONF

cat > /etc/cron.d/swage <<CRON
* * * * * $APP_USER php $APP_ROOT/current/artisan schedule:run > /dev/null 2>&1
CRON
chmod 644 /etc/cron.d/swage

systemctl enable --now supervisor cron
supervisorctl reread
supervisorctl update
