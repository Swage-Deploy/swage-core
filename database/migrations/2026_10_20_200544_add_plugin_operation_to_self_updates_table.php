<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Installing, updating and removing plugins rebuilds Swage the way an update does, so they are recorded alongside
     * updates, with the plugin change that caused the rebuild.
     */
    public function up(): void
    {
        Schema::table('self_updates', function (Blueprint $table) {
            $table->json('plugin_operation')->nullable()->after('to_commit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('self_updates', function (Blueprint $table) {
            $table->dropColumn('plugin_operation');
        });
    }
};
