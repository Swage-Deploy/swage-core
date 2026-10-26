<?php

namespace App\Http\Controllers\Api;

use App\Actions\Sites\CreateSite;
use App\Actions\Sites\DeleteSite;
use App\Actions\Sites\InstallSiteRepository;
use App\Actions\Sites\UpdateSiteSettings;
use App\Http\Controllers\Controller;
use App\Http\Resources\SiteResource;
use App\Models\Server;
use App\Models\Site;
use App\SourceControl\SourceControlProviders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class SiteController extends Controller
{
    /**
     * Every site the user can open: those on servers they can open, and those shared with them on their own.
     */
    public function all(Request $request): AnonymousResourceCollection
    {
        $sites = Site::query()
            ->where(fn ($query) => $query
                ->whereIn('server_id', $request->user()->accessibleServers()->select('id'))
                ->orWhereIn('id', $request->user()->sharedSites()->select('sites.id')))
            ->with('server', 'latestDeployment', 'certificate')
            ->orderBy('domain')
            ->get();

        return SiteResource::collection($sites);
    }

    public function index(Server $server): AnonymousResourceCollection
    {
        return SiteResource::collection($server->sites()->with('server', 'latestDeployment', 'certificate')->orderBy('domain')->get());
    }

    /**
     * Create a site on an app server, and install it there in the background.
     */
    public function store(Request $request, Server $server, CreateSite $createSite): JsonResponse
    {
        Gate::authorize('update', $server);
        abort_unless($server->isAppServer(), Response::HTTP_NOT_FOUND);

        $site = $createSite->fromInput($server, [
            'web_directory' => '/public',
            'php_version' => $server->default_php_version?->value,
            'create_database' => false,
            'database_engine' => $server->defaultDatabaseEngine()?->value,
            'zero_downtime' => true,
            'scheduler' => true,
            ...$request->only(['domain', 'web_directory', 'php_version', 'system_user', 'create_database', 'database', 'database_engine', 'zero_downtime', 'scheduler']),
            'aliases' => $this->aliasesInput($request->input('aliases')),
        ]);

        return SiteResource::make($site->load('server'))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Server $server, Site $site): SiteResource
    {
        return SiteResource::make($site->load('server', 'latestDeployment', 'certificate'));
    }

    /**
     * Change the site's aliases, web directory or PHP version. Fields left out keep their value.
     */
    public function update(Request $request, Server $server, Site $site, UpdateSiteSettings $updateSiteSettings): SiteResource
    {
        Gate::authorize('update', $site);

        $updateSiteSettings->handle($site, [
            'aliases' => implode(', ', $site->aliases ?? []),
            'web_directory' => $site->web_directory,
            'php_version' => $site->php_version->value,
            ...$request->only(['web_directory', 'php_version']),
            ...($request->has('aliases') ? ['aliases' => $this->aliasesInput($request->input('aliases'))] : []),
        ]);

        return SiteResource::make($site->refresh()->load('server'));
    }

    /**
     * Remove the site from its server, with its nginx config, workers, scheduled jobs, certificate and files. Its
     * databases are kept.
     */
    public function destroy(Server $server, Site $site, DeleteSite $deleteSite): JsonResponse
    {
        Gate::authorize('delete', $site);

        $deleteSite->handle($site);

        return response()->json(['message' => __('Deleting the site.')], Response::HTTP_ACCEPTED);
    }

    /**
     * Install a repository: from a provider the server's owner connected, or from a custom clone URL.
     */
    public function repository(Request $request, Server $server, Site $site, InstallSiteRepository $installSiteRepository): SiteResource
    {
        Gate::authorize('update', $site);

        $source = $request->input('source', SourceControlProviders::CUSTOM);

        if ($source !== SourceControlProviders::CUSTOM) {
            Gate::authorize('useSourceControl', $site);
        }

        $installSiteRepository->handle($site, $request->user(), [...$request->only(['repository', 'branch']), 'source' => $source]);

        return SiteResource::make($site->refresh()->load('server'));
    }

    /**
     * Accept aliases as a list or as a string separated by commas or spaces.
     */
    protected function aliasesInput(mixed $aliases): mixed
    {
        return is_array($aliases) ? implode(', ', array_filter($aliases, is_string(...))) : $aliases;
    }
}
