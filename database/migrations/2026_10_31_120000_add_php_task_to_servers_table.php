<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. A server runs one PHP change at a time: installing a version, making one the default or
     * removing one.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('php_task')->nullable()->after('default_php_version');
            $table->string('php_task_version')->nullable()->after('php_task');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['php_task', 'php_task_version']);
        });
    }
};
