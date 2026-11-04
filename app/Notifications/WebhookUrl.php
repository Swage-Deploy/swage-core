<?php

namespace App\Notifications;

/**
 * Checks that a webhook URL points somewhere on the internet. Managers set webhooks, so without this they could have
 * the panel post to services only it can reach, such as ones on its own host or private network.
 */
class WebhookUrl
{
    /**
     * Why the URL can't be used, or null when it can.
     */
    public function problem(string $url): ?string
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? null) !== 'https' || blank($parts['host'] ?? null) || isset($parts['user'])) {
            return __('Enter an https:// URL.');
        }

        $addresses = $this->addresses(trim($parts['host'], '[]'));

        if ($addresses === []) {
            return __('Unable to find :host.', ['host' => $parts['host']]);
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return __(':host is on a private network, which webhooks can\'t post to.', ['host' => $parts['host']]);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }
}
