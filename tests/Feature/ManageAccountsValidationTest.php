<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ManageAccountsValidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $eic;
    protected User $president;
    protected Role $writerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $eicRole = Role::firstOrCreate(['role_name' => 'Editor-in-Chief']);
        $presRole = Role::firstOrCreate(['role_name' => 'President']);
        $this->writerRole = Role::firstOrCreate(['role_name' => 'Writer']);

        $this->eic = User::create([
            'role_id' => $eicRole->id,
            'student_number' => '2023-00001-BN-0',
            'full_name' => 'EIC Account Manager',
            'email' => 'eic@test.com',
            'password' => bcrypt('password123'),
        ]);

        $this->president = User::create([
            'role_id' => $presRole->id,
            'student_number' => '2023-00002-BN-0',
            'full_name' => 'President Account Manager',
            'email' => 'pres@test.com',
            'password' => bcrypt('password123'),
        ]);
    }

    public function test_eic_can_add_officer_with_valid_student_number(): void
    {
        $response = $this->actingAs($this->eic)->postJson('/api/eic/manage-accounts', [
            'student_number' => '2024-00010-BN-0',
            'full_name' => 'Juan Dela Cruz',
            'role_id' => $this->writerRole->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'student_number' => '2024-00010-BN-0',
            'full_name' => 'Juan Dela Cruz',
        ]);
    }

    public function test_eic_cannot_add_officer_with_invalid_student_number_format(): void
    {
        $invalidFormats = [
            '2024-10-BN-0',       // only 2 digits instead of 5
            '24-00010-BN-0',      // only 2 digits for year
            '2024-00010-MN-0',    // MN instead of BN
            '2024-00010-BN-1',    // 1 instead of 0
            '202400010BN0',       // missing hyphens
            'invalid-number',     // random string
        ];

        foreach ($invalidFormats as $invalid) {
            $response = $this->actingAs($this->eic)->postJson('/api/eic/manage-accounts', [
                'student_number' => $invalid,
                'full_name' => 'Test Officer',
                'role_id' => $this->writerRole->id,
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['student_number']);
        }
    }

    public function test_eic_normalizes_lowercase_bn_to_uppercase(): void
    {
        $response = $this->actingAs($this->eic)->postJson('/api/eic/manage-accounts', [
            'student_number' => '2024-00025-bn-0',
            'full_name' => 'Maria Santos',
            'role_id' => $this->writerRole->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'student_number' => '2024-00025-BN-0',
            'full_name' => 'Maria Santos',
        ]);
    }

    public function test_eic_cannot_add_duplicate_student_number(): void
    {
        User::create([
            'role_id' => $this->writerRole->id,
            'student_number' => '2024-00030-BN-0',
            'full_name' => 'Existing Member',
            'email' => 'existing@test.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->actingAs($this->eic)->postJson('/api/eic/manage-accounts', [
            'student_number' => '2024-00030-BN-0',
            'full_name' => 'Another Member',
            'role_id' => $this->writerRole->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['student_number']);
    }

    public function test_executive_can_add_officer_with_valid_student_number(): void
    {
        $response = $this->actingAs($this->president)->postJson('/executive/manage-accounts', [
            'student_number' => '2024-00050-BN-0',
            'full_name' => 'Executive Officer',
            'role_id' => $this->writerRole->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'student_number' => '2024-00050-BN-0',
        ]);
    }

    public function test_executive_cannot_add_officer_with_invalid_student_number(): void
    {
        $response = $this->actingAs($this->president)->postJson('/executive/manage-accounts', [
            'student_number' => 'invalid-student-num',
            'full_name' => 'Executive Officer',
            'role_id' => $this->writerRole->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['student_number']);
    }
}
