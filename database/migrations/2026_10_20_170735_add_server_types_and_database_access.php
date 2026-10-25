<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Database servers run no PHP, so they have no default PHP version. They accept database connections from the
     * addresses in database_access, over the private IP address when the server has one.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('type')->default('app')->after('name');
            $table->string('private_ip_address')->nullable()->after('ip_address');
            $table->json('database_access')->nullable()->after('installing_database');
            $table->string('default_php_version')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['type', 'private_ip_address', 'database_access']);
            $table->string('default_php_version')->nullable(false)->change();
        });
    }
};
