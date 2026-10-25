<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->json('config_overrides')->nullable()->after('tuning');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->text('nginx_override')->nullable()->after('deploy_script');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('config_overrides');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('nginx_override');
        });
    }
};
