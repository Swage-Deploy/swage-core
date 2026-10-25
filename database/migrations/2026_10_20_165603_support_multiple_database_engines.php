<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Servers record each database engine they run instead of a single type, so a second engine can be installed
     * later. Before this, the only type was MySQL, so existing databases and users belong to MySQL.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->boolean('has_mysql')->default(false)->after('database_type');
            $table->boolean('has_postgres')->default(false)->after('has_mysql');
            $table->boolean('has_pgvector')->default(false)->after('has_postgres');
            $table->string('installing_database')->nullable()->after('has_pgvector');
        });

        DB::table('servers')->where('database_type', 'mysql')->update(['has_mysql' => true]);

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('database_type');
        });

        Schema::table('databases', function (Blueprint $table) {
            $table->string('engine')->default('mysql')->after('server_id');
            // The new index is added first, because MySQL may be using the old one for the server_id foreign key.
            $table->unique(['server_id', 'engine', 'name']);
            $table->dropUnique(['server_id', 'name']);
        });

        Schema::table('database_users', function (Blueprint $table) {
            $table->string('engine')->default('mysql')->after('server_id');
            // The new index is added first, because MySQL may be using the old one for the server_id foreign key.
            $table->unique(['server_id', 'engine', 'username']);
            $table->dropUnique(['server_id', 'username']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('database_users', function (Blueprint $table) {
            $table->unique(['server_id', 'username']);
            $table->dropUnique(['server_id', 'engine', 'username']);
            $table->dropColumn('engine');
        });

        Schema::table('databases', function (Blueprint $table) {
            $table->unique(['server_id', 'name']);
            $table->dropUnique(['server_id', 'engine', 'name']);
            $table->dropColumn('engine');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->string('database_type')->nullable()->after('default_php_version');
        });

        DB::table('servers')->where('has_mysql', true)->update(['database_type' => 'mysql']);

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['has_mysql', 'has_postgres', 'has_pgvector', 'installing_database']);
        });
    }
};
