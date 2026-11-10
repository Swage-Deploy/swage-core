<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets only API keys that can read and nothing more use the MCP server, so a key that can deploy or run commands is
 * never handed to an AI assistant by mistake. Signing in with the panel's session doesn't count as a key.
 */
class EnsureReadOnlyApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->user()?->currentAccessToken();

        if (! $key instanceof PersonalAccessToken) {
            return response()->json(['message' => __('Connect with a read-only API key, sent as a bearer token.')], Response::HTTP_UNAUTHORIZED);
        }

        $abilities = $key->abilities ?? [];

        if ($abilities === [] || collect($abilities)->contains(fn (string $ability): bool => ! str_ends_with($ability, ':read'))) {
            return response()->json(['message' => __('The MCP server only accepts read-only API keys. Create one with read access only under Settings, API keys.')], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
