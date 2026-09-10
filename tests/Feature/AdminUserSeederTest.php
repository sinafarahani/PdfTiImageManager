<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_administrator_with_the_configured_password(): void
    {
        config(['app.admin.email' => 'boss@example.com', 'app.admin.password' => 'S3cure-password']);

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'boss@example.com')->sole();
        $this->assertTrue($admin->is_admin);
        $this->assertTrue(Hash::check('S3cure-password', $admin->password));
    }

    public function test_keeps_the_password_of_an_existing_user_when_none_is_configured(): void
    {
        $user = User::factory()->create(['email' => 'boss@example.com', 'password' => 'old-password']);
        config(['app.admin.email' => 'boss@example.com', 'app.admin.password' => null]);

        $this->seed(AdminUserSeeder::class);

        $this->assertTrue($user->fresh()->is_admin);
        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }
}
