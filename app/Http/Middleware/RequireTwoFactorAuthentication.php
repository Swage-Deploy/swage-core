<?php

namespace App\Http\Middleware;

use App\Actions\Users\Impersonation;
use App\Http\Middleware\Concerns\MatchesPageRoutes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTwoFactorAuthentication
{
    use MatchesPageRoutes;

    /**
     * Routes a user without two-factor authentication may still reach, so they can set it up or leave.
     *
     * @var list<string>
     */
    protected const array ALLOWED_ROUTES = [
        'security.edit',
        'logout',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'two-factor.*',
        'verification.*',
        'passkey.*',
        'well-known.passkeys',
    ];

    /**
     * Send users who have not confirmed two-factor authentication to set it up when it is required. An administrator
     * impersonating a user already signed in with their own.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('swage.require_two_factor') || ! $request->user() || app(Impersonation::class)->isImpersonating()) {
            return $next($request);
        }

        abort_if($request->routeIs('two-factor.disable'), Response::HTTP_FORBIDDEN, __('Two-factor authentication is required.'));

        if ($request->user()->hasEnabledTwoFactorAuthentication() || $this->isOnPage($request, self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(Response::HTTP_FORBIDDEN, __('Set up two-factor authentication to continue.'));
        }

        return redirect()->route('security.edit')
            ->with('status', __('Set up two-factor authentication to continue.'));
    }
}
