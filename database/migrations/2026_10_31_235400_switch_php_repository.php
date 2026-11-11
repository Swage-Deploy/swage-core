<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * This queued the move while the queue workers still ran the release before it, which could not load the job, so
     * nothing moved. The swage:move-php-repository command now does it every hour until each server has moved.
     */
    public function up(): void
    {
        //
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
