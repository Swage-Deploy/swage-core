<?php

namespace App\Actions\Sites;

use App\Enums\ResourceStatus;
use App\Jobs\InstallRepository;
use App\Models\Site;
use App\Models\User;
use App\SourceControl\SourceControlProviders;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InstallSiteRepository
{
    public function __construct(protected SourceControlProviders $providers) {}

    /**
     * Point the site at a repository and branch, from a provider the user connected or from a custom clone URL, and
     * install it on the server.
     *
     * Installing from a provider acts through the user's connected account, so only the server's owner should be let
     * this far with one.
     *
     * @param  array{source?: string, repository?: string, branch?: string}  $input
     *
     * @throws ValidationException
     */
    public function handle(Site $site, User $user, array $input): void
    {
        $custom = ($input['source'] ?? null) === SourceControlProviders::CUSTOM;

        $validated = Validator::make($input, [
            'source' => ['required', 'string', function (string $attribute, string $value, Closure $fail) use ($custom, $user): void {
                if (! $custom && ! isset($this->providers->connectedFor($user)[$value])) {
                    $fail(__('Connect an account on this provider before installing a repository from it.'));
                }
            }],
            // A clone URL is quoted into shell commands, so it may only hold the characters SSH URLs use.
            'repository' => $custom
                ? ['required', 'string', 'max:255', 'regex:/^(ssh:\/\/)?[A-Za-z0-9._-]+@[A-Za-z0-9.-]+(:[0-9]+)?[:\/][A-Za-z0-9._~\/-]+$/D']
                : ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.-]+(\/[A-Za-z0-9_.-]+)+$/D'],
            'branch' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\/-]+$/D', 'not_regex:/\.\./'],
        ], [
            'repository.regex' => $custom ? __('Enter an SSH clone URL, such as git@git.example.com:team/app.git.') : __('Choose a repository.'),
        ])->validate();

        // A different repository, or one from a different source, gets a new deploy key.
        if ($site->hasRepository() && ($site->source_control !== $validated['source'] || $site->repository !== $validated['repository'])) {
            $site->update(['deploy_key_private' => null, 'deploy_key_public' => null]);
        }

        $site->update([
            'source_control' => $validated['source'],
            'repository' => $validated['repository'],
            'branch' => $validated['branch'],
            'status' => ResourceStatus::Pending,
        ]);

        InstallRepository::dispatch($site);
    }
}
