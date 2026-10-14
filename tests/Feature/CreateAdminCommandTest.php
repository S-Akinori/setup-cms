<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_eight_character_password_is_accepted(): void
    {
        $this->artisan('cms:create-admin', [
            'email' => 'admin@example.test',
            '--password' => '12345678',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email' => 'admin@example.test',
            'is_admin' => true,
        ]);
    }

    public function test_password_shorter_than_eight_characters_is_rejected(): void
    {
        $this->artisan('cms:create-admin', [
            'email' => 'admin@example.test',
            '--password' => '1234567',
        ])->expectsOutput('パスワードは8文字以上にしてください。')->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'admin@example.test']);
    }
}
