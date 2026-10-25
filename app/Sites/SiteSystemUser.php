<?php

namespace App\Sites;

use App\Models\Server;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Names the system users that isolated sites run as.
 */
class SiteSystemUser
{
    /**
     * The comment Swage gives the accounts it creates for sites, which tells them apart from accounts that were
     * already on the server.
     */
    public const string ACCOUNT_COMMENT = 'Swage site';

    /**
     * Accounts Ubuntu and the provisioned services already use.
     *
     * @var list<string>
     */
    public const array RESERVED = [
        'root', 'daemon', 'bin', 'sys', 'sync', 'games', 'man', 'lp', 'mail', 'news', 'uucp', 'proxy',
        'www-data', 'backup', 'list', 'irc', 'nobody', 'messagebus', 'syslog', 'sshd', 'ubuntu', 'mysql', 'postgres',
        'redis', 'polkitd', 'tss', 'uuidd', 'tcpdump', 'landscape', 'lxd', 'dnsmasq', 'usbmux', 'pollinate',
    ];

    /**
     * Suggest a name for a domain's system user, also used for its database, that no other site on the server has.
     */
    public static function suggest(string $domain, Server $server): string
    {
        $name = Str::of(strtolower($domain))
            ->replaceMatches('/^www\./', '')
            ->beforeLast('.')
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->limit(28, '')
            ->trim('_')
            ->toString();

        if ($name === '' || ! ctype_alpha($name[0])) {
            $name = 'site_'.$name;
        }

        $taken = [
            ...self::RESERVED,
            config('swage.user'),
            ...$server->sites()->whereNotNull('system_user')->pluck('system_user'),
            ...$server->databaseUsers()->pluck('username'),
        ];

        $candidate = $name;

        for ($suffix = 2; in_array($candidate, $taken, true); $suffix++) {
            $candidate = "{$name}_{$suffix}";
        }

        return $candidate;
    }

    /**
     * Validation rules for a new site's system user.
     *
     * @return list<mixed>
     */
    public static function rules(Server $server): array
    {
        return [
            'string',
            'max:32',
            'regex:/^[a-z][a-z0-9_]*$/D',
            Rule::notIn([...self::RESERVED, config('swage.user')]),
            Rule::unique('sites', 'system_user')->where('server_id', $server->id),
        ];
    }
}
