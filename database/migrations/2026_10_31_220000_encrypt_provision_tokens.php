<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store provisioning tokens encrypted, find servers by a hash of them instead, and let them expire.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropUnique(['provision_token']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->text('provision_token')->nullable()->change();
            $table->string('provision_token_hash', 64)->nullable()->unique()->after('provision_token');
            $table->timestamp('provision_token_expires_at')->nullable()->after('provision_token_hash');
        });

        // Commands already handed out keep working for a day from now, as new ones do.
        DB::table('servers')->whereNotNull('provision_token')->select(['id', 'provision_token'])->eachById(function (object $server): void {
            DB::table('servers')->where('id', $server->id)->update([
                'provision_token' => Crypt::encryptString($server->provision_token),
                'provision_token_hash' => hash('sha256', $server->provision_token),
                'provision_token_expires_at' => now()->addDay(),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('servers')->whereNotNull('provision_token')->select(['id', 'provision_token'])->eachById(function (object $server): void {
            DB::table('servers')->where('id', $server->id)->update(['provision_token' => Crypt::decryptString($server->provision_token)]);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropUnique(['provision_token_hash']);
            $table->dropColumn(['provision_token_hash', 'provision_token_expires_at']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->string('provision_token', 64)->nullable()->unique()->change();
        });
    }
};
