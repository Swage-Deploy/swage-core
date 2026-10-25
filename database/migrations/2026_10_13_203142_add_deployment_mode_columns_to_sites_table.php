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
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('zero_downtime')->default(true)->after('web_directory');
            $table->boolean('octane')->default(false)->after('zero_downtime');
            $table->unsignedSmallInteger('octane_port')->nullable()->after('octane');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['zero_downtime', 'octane', 'octane_port']);
        });
    }
};
