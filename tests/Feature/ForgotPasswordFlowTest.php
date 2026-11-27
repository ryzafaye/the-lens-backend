<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\Transports\BrevoApiTransport;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class ForgotPasswordFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Role $writerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writerRole = Role::firstOrCreate(['role_name' => 'Writer']);
        config(['app.frontend_url' => 'https://thelens.example.test']);
    }

    public function test_forgot_password_sends_reset_url_pointing_to_frontend_url(): void
    {
        Notification::fake();

        $user = User::create([
            'role_id' => $this->writerRole->id,
            'student_number' => '2024-00011-BN-0',
            'full_name' => 'Clara Reyes',
            'email' => 'clara.reyes@pup.edu.ph',
            'password' => Hash::make('oldpass123'),
            'account_status' => 'active',
        ]);

        $response = $this->postJson('/api/forgot-password', [
            'student_number' => '2024-00011-BN-0',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'needs_email' => false,
            ]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $mail = $notification->toMail($user);
            $expectedPrefix = 'https://thelens.example.test/reset-password?token=' . urlencode($notification->token)
                . '&email=' . urlencode($user->email);

            $resetUrl = $mail->viewData['resetUrl'] ?? '';

            return $resetUrl === $expectedPrefix
                && $mail->view === 'emails.password-reset';
        });
    }

    public function test_forgot_password_prompts_for_missing_email_and_saves_it_on_system(): void
    {
        Notification::fake();

        $user = User::create([
            'role_id' => $this->writerRole->id,
            'student_number' => '2024-00022-BN-0',
            'full_name' => 'Miguel Torres',
            'email' => null,
            'password' => Hash::make('oldpass123'),
            'account_status' => 'active',
        ]);

        // Step 1: Student number without an email on file asks user for an email
        $promptResponse = $this->postJson('/api/forgot-password', [
            'student_number' => '2024-00022-BN-0',
        ]);

        $promptResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'needs_email' => true,
                'student_number' => '2024-00022-BN-0',
            ]);

        Notification::assertNothingSent();

        // Step 2: User submits email -> saved onto account and reset email dispatched
        $saveAndSendResponse = $this->postJson('/api/forgot-password', [
            'student_number' => '2024-00022-BN-0',
            'email' => 'Miguel.Torres@PUP.edu.ph',
        ]);

        $saveAndSendResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'needs_email' => false,
                'email_saved' => true,
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'miguel.torres@pup.edu.ph',
        ]);

        Notification::assertSentTo($user->fresh(), ResetPassword::class);
    }

    public function test_reset_password_updates_password_and_invalidates_used_token(): void
    {
        $user = User::create([
            'role_id' => $this->writerRole->id,
            'student_number' => '2024-00033-BN-0',
            'full_name' => 'Andres Bonifacio',
            'email' => 'andres@pup.edu.ph',
            'password' => Hash::make('initialpass123'),
            'account_status' => 'active',
        ]);

        $token = Password::broker()->createToken($user);

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'andres@pup.edu.ph',
        ]);

        $response = $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'andres@pup.edu.ph',
            'password' => 'newsecurepass456',
            'password_confirmation' => 'newsecurepass456',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertTrue(Hash::check('newsecurepass456', $user->fresh()->password));

        // Token must be deleted once consumed
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'andres@pup.edu.ph',
        ]);

        // Reusing the same token must fail
        $reuseResponse = $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'andres@pup.edu.ph',
            'password' => 'anotherpass789',
            'password_confirmation' => 'anotherpass789',
        ]);

        $reuseResponse->assertStatus(422);
    }

    public function test_brevo_api_transport_dispatches_over_https_with_api_key_header(): void
    {
        Http::fake([
            'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<msg-123>'], 201),
        ]);

        $transport = new BrevoApiTransport('test-brevo-secret-key');

        $email = (new Email())
            ->from(new Address('lenspupblication.notifications@gmail.com', 'The Lens Publication'))
            ->to(new Address('recipient@pup.edu.ph', 'Recipient Name'))
            ->subject('Test Editorial Email')
            ->html('<p>Hello from The Lens</p>');

        $transport->send($email);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request->hasHeader('api-key', 'test-brevo-secret-key')
                && $request['subject'] === 'Test Editorial Email'
                && $request['sender']['email'] === 'lenspupblication.notifications@gmail.com'
                && $request['to'][0]['email'] === 'recipient@pup.edu.ph';
        });
    }
}
