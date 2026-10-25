<?php

namespace App\Provisioning;

use App\Enums\ServerStatus;
use App\Models\Server;

/**
 * The steps provisioning a server goes through, in the order the provisioning script reports them, and how far a
 * server has got. The script reports each step by its label, so the labels here and in the script must match.
 */
final class ProvisioningProgress
{
    /**
     * The step the bootstrap script completes before the provisioning script reports any of its own.
     */
    public const string HARDWARE_STEP = 'Reporting the server\'s hardware';

    /**
     * The labels of the steps the provisioning script reports, in order.
     *
     * @return list<string>
     */
    public static function scriptSteps(Server $server): array
    {
        return array_values(array_filter([
            'Updating system packages',
            'Creating the '.config('swage.user').' user',
            'Configuring the firewall',
            $server->isAppServer() ? 'Installing nginx' : null,
            $server->isAppServer() ? 'Installing PHP '.$server->php_versions->map->value->implode(', ') : null,
            $server->isAppServer() ? 'Installing Node.js '.config('swage.node_version') : null,
            $server->usesMysql() ? 'Installing MySQL' : null,
            $server->usesPostgres() ? 'Installing PostgreSQL'.($server->has_pgvector ? ' with pgvector' : '') : null,
            $server->has_redis ? 'Installing Redis' : null,
            'Optimizing services for this server',
        ]));
    }

    /**
     * Every step with its state: done, current, failed or pending.
     *
     * @return list<array{label: string, state: 'done'|'current'|'failed'|'pending'}>
     */
    public static function for(Server $server): array
    {
        $labels = [self::HARDWARE_STEP, ...self::scriptSteps($server)];

        $reached = match ($server->status) {
            ServerStatus::Pending => null,
            ServerStatus::Provisioning, ServerStatus::Failed => self::reachedIndex($labels, $server->provisioning_step),
            default => count($labels),
        };

        return array_map(fn (string $label, int $index): array => [
            'label' => $label,
            'state' => match (true) {
                $reached === null => 'pending',
                $index < $reached => 'done',
                $index === $reached => $server->status === ServerStatus::Failed ? 'failed' : 'current',
                default => 'pending',
            },
        ], $labels, array_keys($labels));
    }

    /**
     * The index of the step the server last reported. A failure is recorded as the step with what went wrong after it,
     * such as "Installing nginx (failed at line 84)". Before the script reports its first step, the hardware has
     * already been reported, so the first script step is the one under way.
     *
     * @param  list<string>  $labels
     */
    protected static function reachedIndex(array $labels, ?string $step): int
    {
        foreach (array_reverse($labels, preserve_keys: true) as $index => $label) {
            if ($step === $label || str_starts_with((string) $step, $label.' (')) {
                return $index;
            }
        }

        return 1;
    }
}
