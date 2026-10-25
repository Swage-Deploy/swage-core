<?php

namespace App\Actions\Sites;

use App\Enums\ResourceStatus;
use App\Jobs\ObtainCertificate;
use App\Models\Certificate;
use App\Models\Site;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ObtainSiteCertificate
{
    /**
     * Domains that can be validated over HTTP. Wildcards need DNS validation.
     *
     * @return list<string>
     */
    public static function eligibleDomains(Site $site): array
    {
        return array_values(array_filter($site->domains(), fn (string $domain): bool => ! str_starts_with($domain, '*.')));
    }

    /**
     * Request a Let's Encrypt certificate for the site's domain and the chosen aliases, replacing any it has.
     *
     * @param  list<string>  $domains
     *
     * @throws ValidationException
     */
    public function handle(Site $site, array $domains): Certificate
    {
        $validated = Validator::make(['domains' => $domains], [
            'domains' => ['required', 'array', 'min:1'],
            'domains.*' => [Rule::in(self::eligibleDomains($site))],
        ])->validate();

        $site->certificate?->delete();

        $certificate = $site->certificate()->create([
            'domains' => array_values(array_unique([$site->domain, ...$validated['domains']])),
            'status' => ResourceStatus::Pending,
        ]);

        ObtainCertificate::dispatch($certificate);

        $site->unsetRelation('certificate');

        return $certificate;
    }
}
