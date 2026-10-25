<?php

use Illuminate\Support\Facades\Blade;

test('terminals drop colour codes from command output', function () {
    $html = Blade::render('<x-terminal>{{ $output }}</x-terminal>', [
        'output' => "\e[37;44m INFO \e[39;49m Discovering packages.\n  laravel/fortify \e[90m.\e[39m\e[90m.\e[39m \e[32;1mDONE\e[39;22m",
    ]);

    expect($html)
        ->not->toContain("\e")
        ->toContain(' INFO  Discovering packages.')
        ->toContain('laravel/fortify .. DONE');
});

test('terminals show only the last state of a line that was redrawn', function () {
    $html = Blade::render('<x-terminal>{{ $output }}</x-terminal>', [
        'output' => "Downloading 10%\rDownloading 50%\rDownloading 100%\r\nDone",
    ]);

    expect($html)
        ->toContain("Downloading 100%\nDone")
        ->not->toContain('50%');
});

test('terminals still escape the output', function () {
    $html = Blade::render('<x-terminal>{{ $output }}</x-terminal>', ['output' => "\e[31m<script>alert(1)</script>\e[0m"]);

    expect($html)->toContain('&lt;script&gt;')->not->toContain('<script>');
});
