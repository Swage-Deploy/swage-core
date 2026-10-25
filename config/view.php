<?php

return [

    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    |
    | Most templating systems load templates from disk. Here you may specify
    | an array of paths that should be checked for your views. Of course
    | the usual Laravel view path has already been registered for you.
    |
    */

    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    |
    | This option determines where all the compiled Blade templates will be
    | stored for your application. Each release compiles its own, because
    | storage is shared between releases and Livewire names a compiled
    | component after its path, so a release could otherwise run another
    | release's component.
    |
    */

    'compiled' => env(
        'VIEW_COMPILED_PATH',
        base_path('bootstrap/cache/views')
    ),

];
