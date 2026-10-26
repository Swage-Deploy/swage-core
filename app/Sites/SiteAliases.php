<?php

namespace App\Sites;

use App\Models\Server;
use App\Models\Site;
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

    /**
     * Refuse names another site on the server already answers to, as its domain or an alias.
     *
     * nginx serves a name from whichever site's config it loads first, so a second site claiming a name could take
     * the first one's visitors.
     *
     * @param  list<string>  $names
     *
     * @throws ValidationException
     */
    public static function ensureAvailable(Server $server, array $names, string $field, ?Site $except = null): void
    {
        $taken = $server->sites()
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->get(['id', 'domain', 'aliases'])
            ->flatMap(fn (Site $site): array => array_map(fn (string $name): array => [$name, $site->domain], $site->domains()))
            ->mapWithKeys(fn (array $pair): array => [strtolower($pair[0]) => $pair[1]]);

        foreach ($names as $name) {
            if ($owner = $taken[strtolower($name)] ?? null) {
                throw ValidationException::withMessages([$field => __(':name is already used by :site on this server.', ['name' => $name, 'site' => $owner])]);
            }
        }
    }
}
