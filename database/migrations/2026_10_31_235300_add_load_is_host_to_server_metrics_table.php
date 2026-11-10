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
        Schema::table('server_metrics', function (Blueprint $table) {
            $table->boolean('load_is_host')->default(false)->after('load_15');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_metrics', function (Blueprint $table) {
            $table->dropColumn('load_is_host');
        });
    }
};
