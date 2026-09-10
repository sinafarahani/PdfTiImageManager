<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    /**
     * Creates the administrator (ADMIN_EMAIL). The password is ADMIN_PASSWORD, or a random one that is printed once.
     * An existing administrator keeps its password unless ADMIN_PASSWORD is set.
     */
    public function run(): void
    {
        $configuredPassword = config('app.admin.password');

        $admin = User::firstOrNew(['email' => config('app.admin.email')]);
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
            $this->command?->warn("Password of {$admin->email}: {$generatedPassword} (shown only once; change it after signing in)");
        }
    }
}
