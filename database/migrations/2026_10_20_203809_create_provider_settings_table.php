<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Settings source control providers declare, such as OAuth credentials, entered on their config page. Values are
     * encrypted, since many of them are secrets.
     */
    public function up(): void
    {
        Schema::create('provider_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('key', 64);
            $table->text('value');
            $table->timestamps();

            $table->unique(['provider', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_settings');
    }
};
