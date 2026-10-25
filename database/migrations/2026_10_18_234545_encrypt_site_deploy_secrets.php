<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Encrypt the secrets that let anyone deploy a site: its deploy URL token and its GitHub webhook secret.
     *
     * Encrypted values are longer and differ every time, so the columns become text and the token loses its unique
     * index. Sites are always found by id, never by token.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropUnique(['deploy_token']);
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->text('deploy_token')->change();
            $table->text('webhook_secret')->nullable()->change();
        });

        $this->transform(fn (string $value): string => Crypt::encryptString($value));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->transform(fn (string $value): string => Crypt::decryptString($value));

        Schema::table('sites', function (Blueprint $table) {
            $table->string('deploy_token', 64)->change();
            $table->string('webhook_secret', 64)->nullable()->change();
            $table->unique('deploy_token');
        });
    }

    /**
     * Rewrite every site's deploy token and webhook secret.
     *
     * @param  Closure(string): string  $transform
     */
    protected function transform(Closure $transform): void
    {
        DB::table('sites')->select(['id', 'deploy_token', 'webhook_secret'])->orderBy('id')->each(function (object $site) use ($transform) {
            DB::table('sites')->where('id', $site->id)->update([
                'deploy_token' => $transform($site->deploy_token),
                'webhook_secret' => $site->webhook_secret === null ? null : $transform($site->webhook_secret),
            ]);
        });
    }
};
