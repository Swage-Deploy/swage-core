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
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('is_admin');
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->timestamp('overdue_notified_at')->nullable()->after('expires_at');
        });

        Schema::create('server_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind');
            $table->text('url');
            $table->timestamp('last_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_webhooks');

        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn('overdue_notified_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
