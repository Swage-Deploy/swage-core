<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Registration is disabled, so this is how the administrator account is
     * created. It is safe to run repeatedly: an existing account keeps its
     * password and is only promoted to administrator.
     */
    public function run(): void
    {
        $email = config('swage.admin.email');

        if (blank($email)) {
            throw new RuntimeException('Set SWAGE_ADMIN_EMAIL to the email address of the administrator account, then seed again.');
        }

        $user = User::firstWhere('email', $email);

        if ($user) {
            $user->forceFill(['is_admin' => true, 'email_verified_at' => $user->email_verified_at ?? now()])->save();

            $this->command?->info("Administrator {$email} already exists; password unchanged.");

            return;
        }

        $password = config('swage.admin.password') ?: Str::password(20);

        User::create([
            'name' => config('swage.admin.name'),
            'email' => $email,
            'password' => $password,
            'is_admin' => true,
        ])->forceFill(['email_verified_at' => now()])->save();

        if (! config('swage.admin.password')) {
            $this->command?->warn("Created administrator {$email} with password: {$password}");
            $this->command?->warn('Store it now. It will not be shown again.');
        }
    }
}
