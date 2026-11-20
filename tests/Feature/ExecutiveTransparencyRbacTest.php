<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ExecutiveTransparencyRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_auditor_and_treasurer_can_access_transparency()
    {
        $auditorRole = Role::where('role_name', 'Auditor')->firstOrFail();
        $auditor = User::create([
            'role_id' => $auditorRole->id,
            'full_name' => 'Auditor User',
            'student_number' => '2024-99001-BN-0',
            'email' => 'auditor_rbac@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $response = $this->actingAs($auditor)->getJson('/api/executive/transparency');
        $response->assertOk();

        $treasurerRole = Role::where('role_name', 'Treasurer')->firstOrFail();
        $treasurer = User::create([
            'role_id' => $treasurerRole->id,
            'full_name' => 'Treasurer User',
            'student_number' => '2024-99002-BN-0',
            'email' => 'treasurer_rbac@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $response = $this->actingAs($treasurer)->getJson('/api/executive/transparency');
        $response->assertOk();
    }

    public function test_non_auditor_treasurer_executives_cannot_access_transparency()
    {
        $presidentRole = Role::where('role_name', 'President')->firstOrFail();
        $president = User::create([
            'role_id' => $presidentRole->id,
            'full_name' => 'President User',
            'student_number' => '2024-99003-BN-0',
            'email' => 'president_rbac@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $response = $this->actingAs($president)->getJson('/api/executive/transparency');
        $response->assertStatus(403);
    }

    public function test_executive_dashboard_includes_top_journalists()
    {
        $auditorRole = Role::where('role_name', 'Auditor')->firstOrFail();
        $auditor = User::create([
            'role_id' => $auditorRole->id,
            'full_name' => 'Auditor User',
            'student_number' => '2024-99004-BN-0',
            'email' => 'auditor_rbac2@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $response = $this->actingAs($auditor)->getJson('/api/executive/dashboard');
        $response->assertOk();
        $response->assertJsonStructure([
            'metrics',
            'pendingArticles',
            'publishedArticles',
            'recentActivities',
            'topJournalists',
        ]);
    }
}
