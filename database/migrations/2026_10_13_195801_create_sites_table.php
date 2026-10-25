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
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->json('aliases')->nullable();
            $table->string('web_directory')->default('/public');
            $table->string('php_version');
            $table->string('status')->default('pending');
            $table->string('repository')->nullable();
            $table->string('branch')->nullable();
            $table->text('deploy_key_private')->nullable();
            $table->text('deploy_key_public')->nullable();
            $table->unsignedBigInteger('github_deploy_key_id')->nullable();
            $table->unsignedBigInteger('github_webhook_id')->nullable();
            $table->string('webhook_secret', 64)->nullable();
            $table->text('deploy_script')->nullable();
            $table->boolean('quick_deploy')->default(false);
            $table->string('deploy_token', 64)->unique();
            $table->timestamps();

            $table->unique(['server_id', 'domain']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
