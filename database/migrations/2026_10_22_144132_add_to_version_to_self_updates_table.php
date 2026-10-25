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
        Schema::table('self_updates', function (Blueprint $table) {
            $table->string('to_version')->nullable()->after('to_commit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('self_updates', function (Blueprint $table) {
            $table->dropColumn('to_version');
        });
    }
};
