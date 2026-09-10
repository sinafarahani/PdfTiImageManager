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

    public function test_creates_an_administrator_with_a_random_password_that_is_printed(): void
    {
        config(['app.admin.email' => 'boss@example.com', 'app.admin.password' => null]);

        $this->artisan('db:seed', ['--class' => AdminUserSeeder::class])
            ->expectsOutputToContain('Password of boss@example.com')
            ->assertSuccessful();

        $this->assertTrue(User::where('email', 'boss@example.com')->sole()->is_admin);
    }

    public function test_an_administrator_with_a_mixed_case_email_can_sign_in(): void
    {
        config(['app.admin.email' => 'Boss@Example.com', 'app.admin.password' => 'S3cure-password']);
        $this->seed(AdminUserSeeder::class);

        $this->post('/login', ['email' => 'Boss@Example.com', 'password' => 'S3cure-password']);

        $this->assertAuthenticated();
    }

    public function test_keeps_the_password_of_an_existing_administrator_when_none_is_configured(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'boss@example.com', 'password' => 'old-password']);
        config(['app.admin.email' => 'boss@example.com', 'app.admin.password' => null]);

        $this->artisan('db:seed', ['--class' => AdminUserSeeder::class])
            ->expectsOutputToContain('existing password of boss@example.com was kept')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('old-password', $admin->fresh()->password));
    }

    public function test_does_not_make_an_existing_user_administrator_without_a_configured_password(): void
    {
        $user = User::factory()->create(['email' => 'boss@example.com', 'password' => 'chosen-by-the-user']);
        config(['app.admin.email' => 'boss@example.com', 'app.admin.password' => null]);

        $this->seed(AdminUserSeeder::class);

        $this->assertFalse($user->fresh()->is_admin);
    }

    public function test_makes_an_existing_user_administrator_with_the_configured_password(): void
    {
        $user = User::factory()->create(['email' => 'boss@example.com', 'password' => 'chosen-by-the-user']);
        config(['app.admin.email' => 'boss@example.com', 'app.admin.password' => 'S3cure-password']);

        $this->seed(AdminUserSeeder::class);

        $this->assertTrue($user->fresh()->is_admin);
        $this->assertTrue(Hash::check('S3cure-password', $user->fresh()->password));
    }
}
