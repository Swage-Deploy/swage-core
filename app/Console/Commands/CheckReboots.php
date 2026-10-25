<?php

namespace App\Console\Commands;

use App\Enums\ServerStatus;
use App\Jobs\CheckRebootRequired;
use App\Models\Server;
use Illuminate\Console\Command;

class CheckReboots extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swage:check-reboots';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check which servers need a reboot after automatic updates';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $servers = Server::query()->where('status', ServerStatus::Active)->get();

        $servers->each(fn (Server $server) => CheckRebootRequired::dispatch($server));

        $this->components->info("Checking {$servers->count()} server(s).");

        return self::SUCCESS;
    }
}
