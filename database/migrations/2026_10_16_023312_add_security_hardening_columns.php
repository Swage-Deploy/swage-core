<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('provision_token', 64)->nullable()->change();
            $table->text('host_key')->nullable()->after('ssh_public_key');
            $table->text('redis_password')->nullable()->after('database_password');
            $table->json('applied_upgrades')->nullable()->after('config_overrides');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('private_redis')->default(false)->after('system_user');
        });

        // Provisioned servers never use their token again, so it should no longer unlock anything.
        DB::table('servers')->where('status', 'active')->update(['provision_token' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('private_redis');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['host_key', 'redis_password', 'applied_upgrades']);
        });
    }
};
