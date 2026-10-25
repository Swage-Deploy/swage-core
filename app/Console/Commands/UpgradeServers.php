<?php

namespace App\Console\Commands;

use App\Enums\ServerStatus;
use App\Jobs\UpgradeServer;
use App\Models\Server;
use Illuminate\Console\Command;

class UpgradeServers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swage:upgrade-servers
        {server?* : The ids of the servers to upgrade, every active server when omitted}
        {--list : Only list the servers that have upgrades pending}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bring servers provisioned by an older Swage up to date';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $servers = Server::query()
            ->where('status', ServerStatus::Active)
            ->when($this->argument('server'), fn ($query, array $ids) => $query->whereKey($ids))
            ->get()
            ->filter(fn (Server $server): bool => $server->pendingUpgrades() !== []);

        if ($servers->isEmpty()) {
            $this->components->info('Every server is up to date.');

            return self::SUCCESS;
        }

        foreach ($servers as $server) {
            $titles = collect($server->pendingUpgrades())->map->title()->implode(', ');

            if ($this->option('list')) {
                $this->components->twoColumnDetail("{$server->name} (#{$server->id})", $titles);

                continue;
            }

            UpgradeServer::dispatch($server);

            $this->components->task("Queued {$server->name}: {$titles}");
        }

        return self::SUCCESS;
    }
}
