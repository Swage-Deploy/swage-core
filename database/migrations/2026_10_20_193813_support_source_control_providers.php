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
     * GitHub becomes one source control provider of many: each user keeps one connection per provider, and each site
     * records its provider and the URL its repository is cloned from. Everything before this came from GitHub.
     */
    public function up(): void
    {
        Schema::create('source_control_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('account_id');
            $table->string('username');
            $table->text('token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
        });

        // The token is copied as stored: both columns are encrypted with the same key.
        DB::table('users')->whereNotNull('github_token')->orderBy('id')->each(function (object $user): void {
            DB::table('source_control_connections')->insert([
                'user_id' => $user->id,
                'provider' => 'github',
                'account_id' => (string) $user->github_id,
                'username' => (string) $user->github_username,
                'token' => $user->github_token,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['github_id', 'github_username', 'github_token']);
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->string('source_control', 32)->nullable()->after('status');
            $table->string('clone_url')->nullable()->after('repository');
            $table->string('deploy_key_id')->nullable()->after('deploy_key_public');
            $table->string('webhook_id')->nullable()->after('deploy_key_id');
        });

        DB::table('sites')->whereNotNull('repository')->orderBy('id')->each(function (object $site): void {
            DB::table('sites')->where('id', $site->id)->update([
                'source_control' => 'github',
                'clone_url' => "git@github.com:{$site->repository}.git",
                'deploy_key_id' => $site->github_deploy_key_id === null ? null : (string) $site->github_deploy_key_id,
                'webhook_id' => $site->github_webhook_id === null ? null : (string) $site->github_webhook_id,
            ]);
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['github_deploy_key_id', 'github_webhook_id']);
        });
    }

    /**
     * Reverse the migrations. Connections and sites on other providers cannot be represented and are dropped or
     * cleared.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedBigInteger('github_deploy_key_id')->nullable()->after('deploy_key_public');
            $table->unsignedBigInteger('github_webhook_id')->nullable()->after('github_deploy_key_id');
        });

        DB::table('sites')->where('source_control', 'github')->orderBy('id')->each(function (object $site): void {
            DB::table('sites')->where('id', $site->id)->update([
                'github_deploy_key_id' => $site->deploy_key_id,
                'github_webhook_id' => $site->webhook_id,
            ]);
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['source_control', 'clone_url', 'deploy_key_id', 'webhook_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('github_id')->nullable()->after('remember_token');
            $table->string('github_username')->nullable()->after('github_id');
            $table->text('github_token')->nullable()->after('github_username');
        });

        DB::table('source_control_connections')->where('provider', 'github')->orderBy('id')->each(function (object $connection): void {
            DB::table('users')->where('id', $connection->user_id)->update([
                'github_id' => $connection->account_id,
                'github_username' => $connection->username,
                'github_token' => $connection->token,
            ]);
        });

        Schema::dropIfExists('source_control_connections');
    }
};
