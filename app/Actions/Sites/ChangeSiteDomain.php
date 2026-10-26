<?php

namespace App\Actions\Sites;

use App\Enums\ResourceStatus;
use App\Jobs\ObtainCertificate;
use App\Jobs\UpdateSiteConfiguration;
use App\Models\Site;
use App\Sites\SiteAliases;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChangeSiteDomain
{
    /**
     * Change the domain the site answers to first, keeping the old one as an alias unless asked not to.
     *
     * The site's directory, nginx config, logs and certificate keep their names on the server, so nothing moves and
     * running workers are not disturbed. A site with a certificate gets a new one that covers the new domain, and keeps
     * serving the old one until it is issued.
     *
     * @param  array{domain?: ?string, keep_old_domain?: bool}  $input
     *
     * @throws ValidationException
     */
    public function handle(Site $site, array $input): void
    {
        $input['domain'] = strtolower(trim((string) ($input['domain'] ?? '')));

        $validated = Validator::make($input, [
            'domain' => ['required', 'string', 'max:253', 'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/D', Rule::notIn([$site->domain])],
            'keep_old_domain' => ['boolean'],
        ], [
            'domain.regex' => __('Enter a valid domain name, such as example.com.'),
            'domain.not_in' => __('This is already the site\'s domain.'),
        ])->validate();

        if ($site->certificate?->status->isBusy()) {
            throw ValidationException::withMessages(['domain' => __('Wait for the certificate to finish before changing the domain.')]);
        }

        SiteAliases::ensureAvailable($site->server, [$validated['domain']], 'domain', except: $site);

        $previousDomain = $site->domain;
        $aliases = collect($site->aliases ?? [])
            ->when($validated['keep_old_domain'] ?? true, fn ($aliases) => $aliases->prepend($previousDomain))
            ->reject(fn (string $alias): bool => $alias === $validated['domain'])
            ->unique()
            ->values()
            ->all();

        $site->update([
            'domain' => $validated['domain'],
            'aliases' => $aliases,
            'status' => ResourceStatus::Installing,
        ]);

        $jobs = [new UpdateSiteConfiguration($site, $previousDomain)];

        // The current certificate keeps serving while the new one is requested, so HTTPS visitors are not cut off.
        if ($certificate = $site->certificate?->isActive() ? $site->certificate : null) {
            $certificate->update([
                'domains' => array_values(array_unique([
                    $site->domain,
                    ...array_intersect($certificate->domains, ObtainSiteCertificate::eligibleDomains($site)),
                ])),
            ]);

            $jobs[] = new ObtainCertificate($certificate);
        }

        Bus::chain($jobs)->dispatch();
    }
}
