<?php

namespace App\Sites;

use Illuminate\Validation\ValidationException;

class SiteAliases
{
    /**
     * Split aliases typed with commas or spaces into a list of domains, leaving out the site's own domain.
     *
     * @return list<string>
     *
     * @throws ValidationException when an alias is not a domain name.
     */
    public static function parse(?string $aliases, string $domain): array
    {
        $aliases = collect(preg_split('/[\s,]+/', strtolower($aliases ?? ''), flags: PREG_SPLIT_NO_EMPTY))
            ->reject(fn (string $alias): bool => $alias === $domain)
            ->unique()
            ->values();

        foreach ($aliases as $alias) {
            if (! preg_match('/^(\*\.)?(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/D', $alias)) {
                throw ValidationException::withMessages(['aliases' => __(':alias is not a valid domain name.', ['alias' => $alias])]);
            }
        }

        return $aliases->all();
    }
}
