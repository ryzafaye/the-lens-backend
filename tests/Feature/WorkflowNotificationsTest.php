<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use App\Notifications\EditorialWorkflowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WorkflowNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected Category $newsCategory;
    protected User $writer;
    protected User $newsHead;
    protected User $eic;
    protected User $mpcHead;
    protected User $cartoonist;
    protected User $cartoonistHead;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'https://thelens.example.test']);

        $this->newsCategory = Category::firstOrCreate(['name' => 'News'], ['slug' => 'news']);

        $writerRole = Role::firstOrCreate(['role_name' => 'Writer']);
        $newsHeadRole = Role::firstOrCreate(['role_name' => 'News Head']);
        $eicRole = Role::firstOrCreate(['role_name' => 'Editor-in-Chief']);
        $mpcRole = Role::firstOrCreate(['role_name' => 'Media and Public Communications Head']);
        $cartoonistRole = Role::firstOrCreate(['role_name' => 'Cartoonist']);
        $cartoonHeadRole = Role::firstOrCreate(['role_name' => 'Cartoonist Head']);

        $this->writer = User::create([
            'role_id' => $writerRole->id,
            'student_number' => '2024-00101-BN-0',
            'full_name' => 'Staff Writer',
            'email' => 'writer@pup.edu.ph',
            'password' => Hash::make('password123'),
            'account_status' => 'active',
        ]);

        $this->newsHead = User::create([
            'role_id' => $newsHeadRole->id,
            'student_number' => '2024-00102-BN-0',
            'full_name' => 'News Section Head',
            'email' => 'newshead@pup.edu.ph',
            'password' => Hash::make('password123'),
            'account_status' => 'active',
        ]);

        $this->eic = User::create([
            'role_id' => $eicRole->id,
            'student_number' => '2024-00103-BN-0',
            'full_name' => 'Chief Editor',
            'email' => 'eic@pup.edu.ph',
            'password' => Hash::make('password123'),
            'account_status' => 'active',
        ]);

        $this->mpcHead = User::create([
            'role_id' => $mpcRole->id,
            'student_number' => '2024-00104-BN-0',
            'full_name' => 'MPC Head',
            'email' => 'mpchead@pup.edu.ph',
            'password' => Hash::make('password123'),
            'account_status' => 'active',
        ]);

        $this->cartoonist = User::create([
            'role_id' => $cartoonistRole->id,
            'student_number' => '2024-00105-BN-0',
            'full_name' => 'Editorial Artist',
            'email' => 'cartoonist@pup.edu.ph',
            'password' => Hash::make('password123'),
            'account_status' => 'active',
        ]);

        $this->cartoonistHead = User::create([
            'role_id' => $cartoonHeadRole->id,
            'student_number' => '2024-00106-BN-0',
            'full_name' => 'Cartoon Desk Head',
            'email' => 'cartoonhead@pup.edu.ph',
            'password' => Hash::make('password123'),
            'account_status' => 'active',
        ]);
    }

    public function test_submitting_revising_approving_and_publishing_article_dispatch_notifications(): void
    {
        Notification::fake();

        // 1. Writer submits article -> notifies News Section Head
        $submitResp = $this->actingAs($this->writer)->postJson('/api/writer/submit-article', [
            'title' => 'Campus Solar Initiative',
            'category_id' => $this->newsCategory->id,
            'excerpt' => 'Solar panels installed across campus buildings.',
            'content' => '<p>Campus solar power expansion begins this semester.</p>',
            'status' => 'Pending Review',
        ]);

        $submitResp->assertStatus(200);
        $articleId = (int) ($submitResp->json('article.id') ?? Article::latest('id')->value('id'));

        Notification::assertSentTo(
            $this->newsHead,
            EditorialWorkflowNotification::class,
            fn (EditorialWorkflowNotification $n) => $n->eventType === 'article_submitted'
                && $n->itemTitle === 'Campus Solar Initiative'
                && $n->actionUrl === 'https://thelens.example.test/section-head/review-articles'
        );

        // 2. Section Head requests revision with remarks -> notifies Writer with remarks summary
        Notification::fake();
        $revResp = $this->actingAs($this->newsHead)->postJson("/api/section-head/review-articles/{$articleId}/revision", [
            'remarks' => 'Add budget breakdown in paragraph two.',
        ]);

        $revResp->assertStatus(200);

        Notification::assertSentTo(
            $this->writer,
            EditorialWorkflowNotification::class,
            function (EditorialWorkflowNotification $n) {
                $html = view('emails.workflow-notification', $n->toMail($this->writer)->viewData)->render();

                return $n->eventType === 'article_revision'
                    && str_contains($n->summary, 'News Section Head (News Head)')
                    && str_contains($n->remarks, 'Add budget breakdown in paragraph two.')
                    && !str_contains($html, 'Updated by:')
                    && !str_contains($html, '#faf7f8')
                    && str_contains($html, 'color:#5c0a18')
                    && str_contains($html, 'Status:');
            }
        );

        // 3. EIC approves article -> notifies Author & M&PC Head
        $article = Article::findOrFail($articleId);
        $article->update(['status' => 'Pending EIC Review']);

        Notification::fake();
        $approveResp = $this->actingAs($this->eic)->postJson("/api/eic/review-articles/{$articleId}/approve");
        $approveResp->assertStatus(200);

        Notification::assertSentTo(
            $this->writer,
            EditorialWorkflowNotification::class,
            fn (EditorialWorkflowNotification $n) => $n->eventType === 'article_approved'
        );
        Notification::assertSentTo(
            $this->mpcHead,
            EditorialWorkflowNotification::class,
            fn (EditorialWorkflowNotification $n) => $n->eventType === 'article_approved_for_publish'
                && $n->actionUrl === 'https://thelens.example.test/mpc/publish-article'
        );

        // 4. M&PC publishes article -> notifies Author with live article link
        Notification::fake();
        $pubResp = $this->actingAs($this->mpcHead)->postJson("/api/mpc/articles/{$articleId}/publish");
        $pubResp->assertStatus(200);

        Notification::assertSentTo(
            $this->writer,
            EditorialWorkflowNotification::class,
            fn (EditorialWorkflowNotification $n) => $n->eventType === 'article_published'
                && str_starts_with($n->actionUrl, 'https://thelens.example.test/article/')
        );
    }

    public function test_cartoon_submission_revision_and_approval_dispatch_notifications(): void
    {
        Notification::fake();

        $svgDataUrl = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>');

        // 1. Cartoonist submits cartoon -> notifies Cartoonist Head
        $submitResp = $this->actingAs($this->cartoonist)->postJson('/api/cartoonist/submit-cartoon', [
            'title' => 'Budget Satire Panel',
            'image' => $svgDataUrl,
        ]);

        $submitResp->assertStatus(200);
        $cartoonId = (int) ArticleImage::latest('id')->value('id');

        Notification::assertSentTo(
            $this->cartoonistHead,
            EditorialWorkflowNotification::class,
            fn (EditorialWorkflowNotification $n) => $n->eventType === 'media_submitted'
                && $n->actionUrl === 'https://thelens.example.test/cartoonist/cartoon-desk'
        );

        // 2. Cartoonist Head returns cartoon for revision -> notifies Cartoonist with remarks
        Notification::fake();
        $revResp = $this->actingAs($this->cartoonistHead)->postJson("/api/cartoonist/cartoons/{$cartoonId}/revision", [
            'remarks' => 'Please sharpen dialogue bubble contrast.',
        ]);
        $revResp->assertStatus(200);

        Notification::assertSentTo(
            $this->cartoonist,
            EditorialWorkflowNotification::class,
            fn (EditorialWorkflowNotification $n) => $n->eventType === 'media_revision'
                && str_contains($n->summary, 'Cartoon Desk Head (Cartoonist Head)')
                && str_contains($n->remarks, 'Please sharpen dialogue bubble contrast.')
        );

        // 3. EIC approves cartoon -> notifies Cartoonist & MPC Head
        ArticleImage::findOrFail($cartoonId)->update(['status' => 'pending_eic']);
        Notification::fake();
        $eicApproveResp = $this->actingAs($this->eic)->postJson("/api/eic/media/{$cartoonId}/approve");
        $eicApproveResp->assertStatus(200);

        Notification::assertSentTo(
            $this->cartoonist,
            EditorialWorkflowNotification::class,
            fn (EditorialWorkflowNotification $n) => $n->eventType === 'media_approved'
        );
        Notification::assertSentTo(
            $this->mpcHead,
            EditorialWorkflowNotification::class,
            fn (EditorialWorkflowNotification $n) => $n->eventType === 'media_ready_for_publish'
        );
    }

    public function test_workflow_submission_succeeds_even_if_brevo_email_provider_fails(): void
    {
        config([
            'mail.default' => 'brevo',
            'services.brevo.key' => 'invalid-key',
        ]);

        Http::fake([
            'https://api.brevo.com/*' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $response = $this->actingAs($this->writer)->postJson('/api/writer/submit-article', [
            'title' => 'Resilient Article Workflow',
            'category_id' => $this->newsCategory->id,
            'excerpt' => 'Workflow must never crash when mail fails.',
            'content' => '<p>Submitting while mail provider returns 401.</p>',
            'status' => 'Pending Review',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('articles', [
            'title' => 'Resilient Article Workflow',
            'status' => 'Pending Head Review',
        ]);
    }
}
