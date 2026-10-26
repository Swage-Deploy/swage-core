<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Existing sites keep the names their files already have on the server.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('directory')->nullable()->after('domain');
        });

        DB::table('sites')->update(['directory' => DB::raw('domain')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('directory');
        });
    }
};
