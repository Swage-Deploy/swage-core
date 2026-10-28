<?php

namespace App\Console\Commands;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Ssh\RemoteShell;
use App\Ssh\Script;
use Illuminate\Console\Command;
use Throwable;

class SwageHostMoved extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swage:host-moved
        {--from=* : An address the old panel host connected to servers from}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check every server can be reached from this panel host, and stop fail2ban banning it in place of the old one';

    /**
     * Execute the console command.
     */
    public function handle(RemoteShell $shell): int
    {
        $old = collect($this->option('from'))
            ->flatMap(fn (string $addresses): array => preg_split('/[\s,]+/', $addresses, flags: PREG_SPLIT_NO_EMPTY))
            ->filter(fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP) !== false)
            ->unique()
            ->values()
            ->all();

        $servers = Server::query()->where('status', ServerStatus::Active)->orderBy('name')->get();
        $unreachable = [];

        foreach ($servers as $server) {
            try {
                $result = $shell->run($server, Script::render('host-moved', ['old' => $old]), timeout: 120);
                $message = trim($result->output);
            } catch (Throwable $exception) {
                $result = null;
                $message = $exception->getMessage();
            }

            if ($result?->successful()) {
                $this->components->twoColumnDetail($server->name, $message);

                continue;
            }

            $unreachable[] = $server->name;
            $this->components->twoColumnDetail($server->name, '<fg=red>'.($message ?: 'Failed').'</>');
        }

        if ($unreachable !== []) {
            $this->components->error(count($unreachable).' server(s) could not be updated: '.implode(', ', $unreachable).'.');

            return self::FAILURE;
        }

        $this->components->info("All {$servers->count()} server(s) can be reached from this host.");

        return self::SUCCESS;
    }
}
