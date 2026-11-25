<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateEmailTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['role_name' => 'Editor-in-Chief']);

        $this->user = User::create([
            'role_id' => $role->id,
            'full_name' => 'Jane Doe',
            'student_number' => '2023-00001-BN-0',
            'email' => 'jane.doe@example.com',
            'password' => bcrypt('password123'),
        ]);
    }

    public function test_user_can_update_profile_email(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/profile/update-info', [
            'full_name' => 'Jane Doe Updated',
            'student_number' => '2023-00001-BN-0',
            'email' => 'jane.updated@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Profile updated successfully',
                'user' => [
                    'email' => 'jane.updated@example.com',
                    'full_name' => 'Jane Doe Updated',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'email' => 'jane.updated@example.com',
        ]);
    }

    public function test_user_cannot_update_with_invalid_email(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/profile/update-info', [
            'full_name' => 'Jane Doe',
            'student_number' => '2023-00001-BN-0',
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_user_cannot_update_with_duplicate_email(): void
    {
        $otherUser = User::create([
            'role_id' => $this->user->role_id,
            'student_number' => '2023-00002-BN-0',
            'full_name' => 'Other User',
            'email' => 'taken@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/profile/update-info', [
            'full_name' => 'Jane Doe',
            'student_number' => '2023-00001-BN-0',
            'email' => 'taken@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_user_can_keep_own_existing_email(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/profile/update-info', [
            'full_name' => 'Jane Doe Name Only',
            'student_number' => '2023-00001-BN-0',
            'email' => 'jane.doe@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Profile updated successfully',
                'user' => [
                    'email' => 'jane.doe@example.com',
                ],
            ]);
    }
}
