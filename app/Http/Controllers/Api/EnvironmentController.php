<?php

namespace App\Http\Controllers\Api;

use App\Actions\Sites\ReadSiteEnvironment;
use App\Actions\Sites\WriteSiteEnvironment;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class EnvironmentController extends Controller
{
    /**
     * The site's shared .env file, read from the server. It holds the site's secrets, so only those who can manage the
     * site can read it.
     */
    public function show(Server $server, Site $site, ReadSiteEnvironment $readSiteEnvironment): JsonResponse
    {
        Gate::authorize('update', $site);

        try {
            $environment = $readSiteEnvironment->handle($site);
        } catch (Throwable $exception) {
            return response()->json(['message' => __('Unable to read the environment file: :output', ['output' => $exception->getMessage()])], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json(['data' => ['environment' => $environment]]);
    }

    /**
     * Replace the site's .env file. The config cache is cleared unless clear_config is false.
     */
    public function update(Request $request, Server $server, Site $site, WriteSiteEnvironment $writeSiteEnvironment): JsonResponse
    {
        Gate::authorize('update', $site);

        $validated = $request->validate(['clear_config' => ['boolean']]);

        try {
            $writeSiteEnvironment->handle($site, $request->input('environment'), (bool) ($validated['clear_config'] ?? true));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return response()->json(['message' => __('Unable to save the environment: :output', ['output' => $exception->getMessage()])], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json(['message' => __('Environment saved.')]);
    }
}
