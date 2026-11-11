<?php

namespace App\Console\Commands;

use App\Enums\ServerStatus;
use App\Enums\ServerType;
use App\Jobs\MovePhpRepository;
use App\Models\Server;
use Illuminate\Console\Command;

class MovePhpRepositories extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swage:move-php-repository';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Move app servers still on the ondrej PPA to packages.sury.org';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $servers = Server::query()
            ->where('status', ServerStatus::Active)
            ->where('type', '!=', ServerType::Database)
            ->whereNull('php_repository_moved_at')
            ->get();

        $servers->each(fn (Server $server) => MovePhpRepository::dispatch($server));

        $this->components->info("Moving {$servers->count()} server(s) to packages.sury.org.");

        return self::SUCCESS;
    }
}
