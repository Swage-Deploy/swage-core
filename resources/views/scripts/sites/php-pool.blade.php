@php($pool = App\Sites\SitePhpPool::for($site))
# The PHP-FPM pool for {!! $site->domain !!}, under its current PHP version only.
@include('scripts.partials.write-files', ['files' => $pool->files()])
rm -f {!! collect($pool->stalePaths())->map(fn ($path) => escapeshellarg($path))->implode(' ') !!}
@foreach ($site->server->php_versions as $php)
php-fpm{!! $php->value !!} -t
systemctl reload {!! $php->fpmService() !!}
@endforeach
