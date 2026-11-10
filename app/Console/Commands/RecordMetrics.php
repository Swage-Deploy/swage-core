<?php

namespace App\Console\Commands;

use App\Enums\ServerStatus;
use App\Jobs\RecordServerMetrics;
use App\Models\Server;
use Illuminate\Console\Command;

class RecordMetrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swage:record-metrics';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Read every server\'s CPU, memory, disk and load';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $servers = Server::query()->where('status', ServerStatus::Active)->get();

        $servers->each(fn (Server $server) => RecordServerMetrics::dispatch($server));

        $this->components->info("Reading {$servers->count()} server(s).");

        return self::SUCCESS;
    }
}
