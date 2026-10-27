{{-- Supervisor opens the log as root, so it lives where no site's user can swap it for a link to another file. --}}
install -d -m 750 -o root -g adm {!! App\Models\Daemon::LOG_DIRECTORY !!}

cat > '{!! $daemon->configPath() !!}' <<'SWAGE_EOF'
[program:{!! $daemon->programName() !!}]
{{-- Supervisor expands %(name)s in commands, so a literal % is written as %%. --}}
command={!! str_replace('%', '%%', $daemon->command) !!}
@if ($daemon->directory)
directory={!! $daemon->directory !!}
@endif
user={!! $daemon->user !!}
process_name=%(program_name)s_%(process_num)02d
numprocs={!! $daemon->processes !!}
autostart=true
autorestart=true
startsecs=1
stopwaitsecs={!! $daemon->stop_wait_seconds !!}
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile={!! $daemon->logPath() !!}
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
SWAGE_EOF
