<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\FinancialRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialRecordModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_financial_record_can_be_created_and_has_relationships(): void
    {
        $treasurerRole = Role::where('role_name', 'Treasurer')->firstOrFail();
        $officer = User::create([
            'role_id' => $treasurerRole->id,
            'student_number' => '2024-88001-BN-0',
            'full_name' => 'Treasurer Officer',
            'email' => 'treasurer_fin@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $record = FinancialRecord::create([
            'uploader_id' => $officer->id,
            'title' => 'FY 2026-2027 1st Semester Financial Statement',
            'file_name' => 'financial_statement_q1.pdf',
            'file_url' => 'https://res.cloudinary.com/the-lens/raw/upload/v1/fin/financial_statement_q1.pdf',
            'public_id' => 'the-lens/financial_records/financial_statement_q1',
            'resource_type' => 'raw',
            'file_size' => 2048500,
        ]);

        $this->assertDatabaseHas('financial_records', [
            'id' => $record->id,
            'title' => 'FY 2026-2027 1st Semester Financial Statement',
            'file_name' => 'financial_statement_q1.pdf',
            'resource_type' => 'raw',
            'file_size' => 2048500,
        ]);

        // BelongsTo relationship
        $this->assertNotNull($record->uploader);
        $this->assertSame($officer->id, $record->uploader->id);
        $this->assertSame('Treasurer Officer', $record->uploader->full_name);

        // HasMany relationship on User
        $officerRecords = $officer->financialRecords;
        $this->assertCount(1, $officerRecords);
        $this->assertSame($record->id, $officerRecords->first()->id);

        // Test nullable on delete: financial records preserved even if officer account is removed
        $officer->delete();
        $record->refresh();
        $this->assertNull($record->uploader_id);
        $this->assertNull($record->uploader);
    }
}
