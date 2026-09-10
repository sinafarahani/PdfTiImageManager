<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    /**
     * Creates the administrator (ADMIN_EMAIL). The password is ADMIN_PASSWORD, or a random one that is printed once.
     * An existing administrator keeps its password unless ADMIN_PASSWORD is set. An existing account that is not an
     * administrator only becomes one when ADMIN_PASSWORD is set, which then replaces its password: anyone could have
     * registered with that email.
     */
    public function run(): void
    {
        $email = Str::lower((string) config('app.admin.email'));
        $configuredPassword = config('app.admin.password');
        $admin = User::firstOrNew(['email' => $email]);

        if ($admin->exists && ! $admin->is_admin && ! $configuredPassword) {
            $this->command?->error("{$email} belongs to an account that is not an administrator. Set ADMIN_PASSWORD to make it the administrator with that password.");

            return;
        }

        $admin->name = $admin->name ?: 'Admin';
        $admin->is_admin = true;

        $generatedPassword = null;
        if ($configuredPassword) {
            $admin->password = $configuredPassword;
        } elseif (! $admin->exists) {
            $generatedPassword = Str::password(20);
            $admin->password = $generatedPassword;
        }

        $admin->save();

        if ($generatedPassword !== null) {
            $this->command?->warn("Password of {$email}: {$generatedPassword} (shown only once; change it after signing in)");
        } elseif (! $configuredPassword) {
            $this->command?->warn("The existing password of {$email} was kept. If others may know it, set ADMIN_PASSWORD and seed again, or change it on the profile page.");
        }
    }
}
