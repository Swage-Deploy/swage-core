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
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('ip_address');
            $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->string('status')->default('pending');
            $table->string('provision_token', 64)->unique();
            $table->string('provisioning_step')->nullable();
            $table->unsignedSmallInteger('cpu_cores')->nullable();
            $table->unsignedInteger('memory_mb')->nullable();
            $table->unsignedInteger('disk_gb')->nullable();
            $table->string('os_version')->nullable();
            $table->json('php_versions');
            $table->string('default_php_version');
            $table->string('database_type')->nullable();
            $table->boolean('has_redis')->default(true);
            $table->text('ssh_private_key');
            $table->text('ssh_public_key');
            $table->text('database_password');
            $table->json('tuning')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
