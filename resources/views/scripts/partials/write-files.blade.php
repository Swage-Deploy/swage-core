@foreach ($files as $path => $contents)
@php($delimiter = 'SWAGE_FILE_'.Illuminate\Support\Str::upper(Illuminate\Support\Str::random(16)))
mkdir -p {!! escapeshellarg(dirname($path)) !!}
@if ($contents === '')
: > {!! escapeshellarg($path) !!}
@else
cat > {!! escapeshellarg($path) !!} <<'{!! $delimiter !!}'
{!! rtrim($contents, "\n") !!}
{!! $delimiter !!}
@endif
@endforeach
