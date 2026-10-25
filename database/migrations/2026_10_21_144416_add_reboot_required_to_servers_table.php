<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Automatic security updates never reboot a server, so Swage records when one needs it and which packages asked.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->timestamp('reboot_required_at')->nullable()->after('applied_upgrades');
            $table->json('reboot_required_packages')->nullable()->after('reboot_required_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['reboot_required_at', 'reboot_required_packages']);
        });
    }
};
