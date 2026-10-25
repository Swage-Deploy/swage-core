<?php

namespace App\Http\Controllers\Api;

use App\Actions\Sites\ObtainSiteCertificate;
use App\Actions\Sites\RemoveSiteCertificate;
use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class CertificateController extends Controller
{
    public function show(Server $server, Site $site): JsonResponse
    {
        return response()->json(['data' => $site->certificate ? CertificateResource::make($site->certificate) : null]);
    }

    /**
     * Request a certificate from Let's Encrypt for the site's domain and its aliases, or the aliases given.
     */
    public function store(Request $request, Server $server, Site $site, ObtainSiteCertificate $obtainSiteCertificate): JsonResponse
    {
        Gate::authorize('update', $site);

        $certificate = $obtainSiteCertificate->handle($site, (array) $request->input('domains', ObtainSiteCertificate::eligibleDomains($site)));

        return CertificateResource::make($certificate)->response()->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function destroy(Server $server, Site $site, RemoveSiteCertificate $removeSiteCertificate): JsonResponse
    {
        Gate::authorize('update', $site);

        $removeSiteCertificate->handle($site);

        return response()->json(['message' => __('Removing the certificate.')], Response::HTTP_ACCEPTED);
    }
}
