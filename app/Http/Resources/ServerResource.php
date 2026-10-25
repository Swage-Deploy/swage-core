<?php

namespace App\Http\Resources;

use App\Enums\PhpVersion;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Server
 */
class ServerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canUpdate = $request->user()->can('update', $this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'status' => $this->status,
            'provisioning_step' => $this->provisioning_step,
            'ip_address' => $this->ip_address,
            'private_ip_address' => $this->private_ip_address,
            'ssh_port' => $this->ssh_port,
            'os_version' => $this->os_version,
            'cpu_cores' => $this->cpu_cores,
            'memory_mb' => $this->memory_mb,
            'disk_gb' => $this->disk_gb,
            'php_versions' => $this->php_versions?->map(fn (PhpVersion $version): string => $version->value)->values(),
            'default_php_version' => $this->default_php_version,
            'database_engines' => array_column($this->databaseEngines(), 'value'),
            'has_pgvector' => $this->has_pgvector,
            'has_redis' => $this->has_redis,
            'reboot_required' => $this->reboot_required_at !== null,
            'owner' => ['id' => $this->user_id, 'name' => $this->user?->name],
            'can' => [
                'update' => $canUpdate,
                'delete' => $request->user()->can('delete', $this->resource),
            ],
            // Whoever can manage the server can see the command that provisions it, as on its page.
            'provision_command' => $this->when($canUpdate && ! $this->isActive(), fn (): string => $this->provisionCommand()),
            'provisioned_at' => $this->provisioned_at,
            'created_at' => $this->created_at,
        ];
    }
}
