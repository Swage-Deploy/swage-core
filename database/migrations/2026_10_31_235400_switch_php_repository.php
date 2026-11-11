<?php

use App\Enums\ServerStatus;
use App\Enums\ServerType;
use App\Jobs\SwitchPhpRepository;
use App\Models\Server;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Move every app server's PHP from the ondrej PPA, which is being retired, to packages.sury.org. Servers provisioned
     * from now on use it from the start.
     */
    public function up(): void
    {
        Server::query()
            ->where('status', ServerStatus::Active)
            ->where('type', '!=', ServerType::Database)
            ->each(fn (Server $server) => SwitchPhpRepository::dispatch($server));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
