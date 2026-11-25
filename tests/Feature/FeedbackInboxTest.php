<?php

namespace Tests\Feature;

use App\Models\Feedback;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedbackInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_feedback_submission_saves_email_to_database(): void
    {
        $response = $this->postJson('/api/public/feedback', [
            'name' => 'Maria Clara',
            'email' => 'maria.clara@example.com',
            'subject' => 'Great Campus Coverage',
            'message' => 'Thank you for the thorough coverage of the student assembly.',
            'category' => 'editorial',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('feedback', [
            'name' => 'Maria Clara',
            'email' => 'maria.clara@example.com',
            'subject' => 'Great Campus Coverage',
            'is_read' => false,
        ]);
    }

    public function test_public_feedback_rejects_invalid_email(): void
    {
        $response = $this->postJson('/api/public/feedback', [
            'name' => 'Maria Clara',
            'email' => 'invalid-email-format',
            'subject' => 'Test Subject',
            'message' => 'Test message body.',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_eic_can_view_feedback_inbox_and_mark_message_as_read(): void
    {
        $eicRole = Role::firstOrCreate(['role_name' => 'Editor-in-Chief']);
        $eic = User::create([
            'role_id' => $eicRole->id,
            'full_name' => 'EIC Officer',
            'student_number' => '2023-10001-BN-0',
            'password' => bcrypt('password123'),
        ]);

        $feedback = Feedback::create([
            'name' => 'Student Reader',
            'email' => 'reader@example.com',
            'subject' => 'Clarification Request',
            'message' => 'Could you share the schedule for the next issue?',
            'is_read' => false,
        ]);

        $listResponse = $this->actingAs($eic)->getJson('/api/eic/feedback-inbox');
        $listResponse->assertStatus(200)
            ->assertJsonCount(1, 'feedbacks')
            ->assertJsonFragment([
                'id' => $feedback->id,
                'email' => 'reader@example.com',
                'subject' => 'Clarification Request',
                'isRead' => false,
            ]);

        $readResponse = $this->actingAs($eic)->patchJson("/api/eic/feedback-inbox/{$feedback->id}/read");
        $readResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'id' => $feedback->id,
                'is_read' => true,
            ]);

        $this->assertDatabaseHas('feedback', [
            'id' => $feedback->id,
            'is_read' => true,
        ]);
    }

    public function test_president_can_view_feedback_inbox_and_mark_message_as_read(): void
    {
        $presRole = Role::firstOrCreate(['role_name' => 'President']);
        $president = User::create([
            'role_id' => $presRole->id,
            'full_name' => 'Org President',
            'student_number' => '2023-20001-BN-0',
            'password' => bcrypt('password123'),
        ]);

        $feedback = Feedback::create([
            'name' => null,
            'email' => 'anon.tip@example.com',
            'subject' => 'Editorial Tip',
            'message' => 'Please look into the campus library hours.',
            'is_read' => false,
        ]);

        $listResponse = $this->actingAs($president)->getJson('/api/executive/feedback-inbox');
        $listResponse->assertStatus(200)
            ->assertJsonCount(1, 'feedbacks')
            ->assertJsonFragment([
                'id' => $feedback->id,
                'email' => 'anon.tip@example.com',
                'subject' => 'Editorial Tip',
                'isRead' => false,
            ]);

        $readResponse = $this->actingAs($president)->patchJson("/api/executive/feedback-inbox/{$feedback->id}/read");
        $readResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'id' => $feedback->id,
                'is_read' => true,
            ]);

        $this->assertDatabaseHas('feedback', [
            'id' => $feedback->id,
            'is_read' => true,
        ]);
    }

    public function test_non_pres_vp_executive_cannot_access_feedback_inbox(): void
    {
        $treasurerRole = Role::firstOrCreate(['role_name' => 'Treasurer']);
        $treasurer = User::create([
            'role_id' => $treasurerRole->id,
            'full_name' => 'Org Treasurer',
            'student_number' => '2023-30001-BN-0',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->actingAs($treasurer)->getJson('/api/executive/feedback-inbox');
        $response->assertStatus(403);
    }
}
