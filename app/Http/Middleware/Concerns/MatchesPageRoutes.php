<?php

namespace App\Http\Middleware\Concerns;

use Illuminate\Http\Request;
use Livewire\Livewire;

trait MatchesPageRoutes
{
    /**
     * Determine if the request comes from a page whose route has one of the names.
     *
     * A Livewire update goes through Livewire's own endpoint and may carry several components. With $every, every one of
     * them must have been rendered on a matching page; without it, any one is enough. Each snapshot's checksum, path
     * included, is verified when Livewire hydrates it, so a forged path fails the request.
     *
     * @param  list<string>  $routes
     */
    protected function isOnPage(Request $request, array $routes, bool $every = true): bool
    {
        if (! Livewire::isLivewireRequest()) {
            return $request->routeIs(...$routes);
        }

        $paths = collect($request->input('components'))
            ->map(fn (mixed $component): mixed => data_get(json_decode((string) data_get($component, 'snapshot'), true), 'memo.path'));

        $matches = fn (mixed $path): bool => is_string($path)
            && (bool) rescue(fn (): ?bool => app('router')->getRoutes()->match(Request::create(url()->to($path)))->named(...$routes), report: false);

        return $paths->isNotEmpty() && ($every ? $paths->every($matches) : $paths->contains($matches));
    }
}
