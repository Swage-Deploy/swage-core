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
        Schema::create('server_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->float('cpu_percent');
            $table->float('load_1');
            $table->float('load_5');
            $table->float('load_15');
            $table->unsignedBigInteger('memory_total');
            $table->unsignedBigInteger('memory_used');
            $table->unsignedBigInteger('swap_total');
            $table->unsignedBigInteger('swap_used');
            $table->unsignedBigInteger('disk_total');
            $table->unsignedBigInteger('disk_used');
            $table->unsignedBigInteger('uptime_seconds');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['server_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_metrics');
    }
};
