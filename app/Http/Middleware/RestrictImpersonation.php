<?php

namespace App\Http\Middleware;

use App\Actions\Users\Impersonation;
use App\Http\Middleware\Concerns\MatchesPageRoutes;
use Closure;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

class RestrictImpersonation
{
    use MatchesPageRoutes;

    /**
     * Pages that change how a user signs in or which accounts they connect, which an administrator impersonating them
     * may not use.
     *
     * @var list<string>
     */
    protected const array BLOCKED_ROUTES = [
        'profile.edit',
        'security.edit',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'two-factor.*',
        'passkey.*',
        'source-control.edit',
        'source-control.redirect',
        'source-control.callback',
    ];

    /**
     * Send a page request for a blocked page back to the dashboard, where the impersonation banner says why. Livewire
     * updates from a blocked page are refused outright.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(Impersonation::class)->isImpersonating() || ! $this->isOnPage($request, self::BLOCKED_ROUTES, every: false)) {
            return $next($request);
        }

        if (Livewire::isLivewireRequest() || $request->expectsJson()) {
            abort(Response::HTTP_FORBIDDEN, __('Not available while signed in as another user.'));
        }

        return redirect()->route('dashboard')->with('impersonation-blocked', true);
    }
}
