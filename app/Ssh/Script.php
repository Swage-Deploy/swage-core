<?php

namespace App\Ssh;

class Script
{
    /**
     * Render a shell script from a Blade template under "scripts".
     *
     * @param  array<string, mixed>  $data
     */
    public static function render(string $name, array $data = []): string
    {
        return view("scripts.{$name}", ['user' => config('swage.user'), ...$data])->render();
    }
}
