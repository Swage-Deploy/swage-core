<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One reading of a server's CPU, memory, disk and load, taken every few minutes for the server's overview.
 * Sizes are in bytes.
 *
 * @property int $id
 * @property int $server_id
 * @property float $cpu_percent
 * @property float $load_1
 * @property float $load_5
 * @property float $load_15
 * @property int $memory_total
 * @property int $memory_used
 * @property int $swap_total
 * @property int $swap_used
 * @property int $disk_total
 * @property int $disk_used
 * @property int $uptime_seconds
 * @property Carbon $created_at
 */
#[Fillable([
    'cpu_percent', 'load_1', 'load_5', 'load_15', 'memory_total', 'memory_used', 'swap_total', 'swap_used',
    'disk_total', 'disk_used', 'uptime_seconds',
])]
class ServerMetric extends Model
{
    use MassPrunable;

    /**
     * How long readings are kept.
     */
    public const int RETENTION_DAYS = 7;

    public const null UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cpu_percent' => 'float',
            'load_1' => 'float',
            'load_5' => 'float',
            'load_15' => 'float',
            'memory_total' => 'integer',
            'memory_used' => 'integer',
            'swap_total' => 'integer',
            'swap_used' => 'integer',
            'disk_total' => 'integer',
            'disk_used' => 'integer',
            'uptime_seconds' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return Builder<ServerMetric>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    public function memoryPercent(): float
    {
        return $this->memory_total > 0 ? $this->memory_used / $this->memory_total * 100 : 0;
    }

    public function swapPercent(): float
    {
        return $this->swap_total > 0 ? $this->swap_used / $this->swap_total * 100 : 0;
    }

    public function diskPercent(): float
    {
        return $this->disk_total > 0 ? $this->disk_used / $this->disk_total * 100 : 0;
    }

    /**
     * Read the output of the server-metrics script, or null when it is not what the script prints.
     *
     * @return array<string, int|float>|null
     */
    public static function parse(string $output): ?array
    {
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $output))));

        if (($lines[0] ?? null) !== 'SWAGE_METRICS') {
            return null;
        }

        $cpu = [];
        $values = [];

        foreach (array_slice($lines, 1) as $line) {
            $fields = preg_split('/\s+/', $line);
            $key = rtrim(array_shift($fields), ':');

            if ($key === 'cpu') {
                $cpu[] = array_map(intval(...), $fields);
            } else {
                $values[$key] = $fields;
            }
        }

        foreach (['MemTotal', 'MemAvailable', 'SwapTotal', 'SwapFree', 'loadavg', 'uptime', 'disk'] as $required) {
            if (! isset($values[$required])) {
                return null;
            }
        }

        if (count($cpu) !== 2) {
            return null;
        }

        // The fourth and fifth fields of /proc/stat are idle and iowait time; everything else is time spent working.
        [$before, $after] = $cpu;
        $total = array_sum(array_slice($after, 0, 8)) - array_sum(array_slice($before, 0, 8));
        $idle = ($after[3] + ($after[4] ?? 0)) - ($before[3] + ($before[4] ?? 0));

        $kilobytes = fn (string $key): int => (int) $values[$key][0] * 1024;

        return [
            'cpu_percent' => $total > 0 ? round(max(0, min(100, ($total - $idle) / $total * 100)), 1) : 0.0,
            'load_1' => (float) $values['loadavg'][0],
            'load_5' => (float) ($values['loadavg'][1] ?? 0),
            'load_15' => (float) ($values['loadavg'][2] ?? 0),
            'memory_total' => $kilobytes('MemTotal'),
            'memory_used' => max(0, $kilobytes('MemTotal') - $kilobytes('MemAvailable')),
            'swap_total' => $kilobytes('SwapTotal'),
            'swap_used' => max(0, $kilobytes('SwapTotal') - $kilobytes('SwapFree')),
            'disk_total' => (int) $values['disk'][0],
            'disk_used' => (int) ($values['disk'][1] ?? 0),
            'uptime_seconds' => (int) $values['uptime'][0],
        ];
    }
}
