<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExecutiveAndCartoonistWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $president;
    protected User $treasurer;
    protected User $eic;
    protected User $mpcHead;
    protected User $cartoonistHead;
    protected User $staffCartoonist;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $presRole = Role::firstOrCreate(['role_name' => 'President']);
        $treasRole = Role::firstOrCreate(['role_name' => 'Treasurer']);
        $eicRole = Role::firstOrCreate(['role_name' => 'Editor-in-Chief']);
        $mpcRole = Role::firstOrCreate(['role_name' => 'Media and Public Communications Head']);
        $cartHeadRole = Role::firstOrCreate(['role_name' => 'Cartoonist Head']);
        $cartRole = Role::firstOrCreate(['role_name' => 'Cartoonist']);

        $this->president = User::create([
            'role_id' => $presRole->id,
            'full_name' => 'Patty President',
            'student_number' => '2024-99001-BN-0',
            'email' => 'patty@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $this->treasurer = User::create([
            'role_id' => $treasRole->id,
            'full_name' => 'Trevor Treasurer',
            'student_number' => '2024-99002-BN-0',
            'email' => 'trevor@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $this->eic = User::create([
            'role_id' => $eicRole->id,
            'full_name' => 'Elena EIC',
            'student_number' => '2024-99003-BN-0',
            'email' => 'elena@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $this->mpcHead = User::create([
            'role_id' => $mpcRole->id,
            'full_name' => 'Marcus MPC',
            'student_number' => '2024-99004-BN-0',
            'email' => 'marcus@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $this->cartoonistHead = User::create([
            'role_id' => $cartHeadRole->id,
            'full_name' => 'Carl Cartoonist Head',
            'student_number' => '2024-99005-BN-0',
            'email' => 'carl@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $this->staffCartoonist = User::create([
            'role_id' => $cartRole->id,
            'full_name' => 'Sam Cartoonist',
            'student_number' => '2024-99006-BN-0',
            'email' => 'sam@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $this->category = Category::firstOrCreate(
            ['name' => 'News'],
            ['slug' => 'news']
        );
    }

    public function test_executive_submitting_article_routes_directly_to_pending_eic_review()
    {
        $response = $this->actingAs($this->treasurer)->postJson('/api/executive/submit-article', [
            'title' => 'Fiscal Transparency Report 2026',
            'category_id' => $this->category->id,
            'excerpt' => 'Annual financial allocation overview for student publication operations.',
            'content' => '<p>Detailed breakdown of expenditures and publication allocations.</p>',
            'status' => 'Pending Review',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Article submitted and forwarded to Editor-in-Chief for approval.',
            ]);

        $this->assertDatabaseHas('articles', [
            'title' => 'Fiscal Transparency Report 2026',
            'user_id' => $this->treasurer->id,
            'status' => 'Pending EIC Review',
        ]);
    }

    public function test_eic_sees_executive_submission_with_direct_submission_label_and_can_approve_it_for_mpc()
    {
        $article = Article::create([
            'user_id' => $this->treasurer->id,
            'category_id' => $this->category->id,
            'title' => 'Executive Budget Analysis',
            'excerpt' => 'Analysis of allocations',
            'content' => '<p>Executive budget details</p>',
            'status' => 'Pending EIC Review',
            'views' => 0,
            'shares' => 0,
        ]);

        // EIC sees it in review queue
        $reviewRes = $this->actingAs($this->eic)->getJson('/api/eic/review-articles');
        $reviewRes->assertOk();
        $articles = collect($reviewRes->json('pendingArticles'));
        $found = $articles->firstWhere('id', $article->id);
        $this->assertNotNull($found, 'EIC review queue must contain executive article.');
        $this->assertStringContainsString('Treasurer', $found['endorsedBy']);

        // EIC approves it -> becomes Approved for MPC
        $approveRes = $this->actingAs($this->eic)->postJson("/api/eic/review-articles/{$article->id}/approve");
        $approveRes->assertOk();
        $this->assertEquals('Approved', $article->fresh()->status);

        // MPC sees it in publish queue and can publish it
        $mpcRes = $this->actingAs($this->mpcHead)->getJson('/api/mpc/publish-article');
        $mpcRes->assertOk();
        $mpcArticles = collect($mpcRes->json('articles'));
        $this->assertTrue($mpcArticles->contains('id', $article->id), 'MPC publish queue must contain EIC-approved executive article.');

        $publishRes = $this->actingAs($this->mpcHead)->postJson("/api/mpc/articles/{$article->id}/publish");
        $publishRes->assertOk();
        $this->assertEquals('Published', $article->fresh()->status);
    }

    public function test_eic_returning_executive_article_routes_to_writer_revision_not_head_revision()
    {
        $article = Article::create([
            'user_id' => $this->treasurer->id,
            'category_id' => $this->category->id,
            'title' => 'Executive Draft With Edits Needed',
            'excerpt' => 'Needs revision',
            'content' => '<p>Needs revision</p>',
            'status' => 'Pending EIC Review',
            'views' => 0,
            'shares' => 0,
        ]);

        $returnRes = $this->actingAs($this->eic)->postJson("/api/eic/review-articles/{$article->id}/revision", [
            'remarks' => 'Please provide more specific budget breakdowns.',
        ]);

        $returnRes->assertOk();
        $fresh = $article->fresh();
        $this->assertEquals('For Writer Revision', $fresh->status, 'Returned executive article must go to writer revision, never head revision.');
        $this->assertEquals('Please provide more specific budget breakdowns.', $fresh->remarks);
    }

    public function test_cartoonist_head_resolves_to_cartoonist_portal_not_section_head()
    {
        $authController = new AuthController();
        $portal = $authController->resolvePortal($this->cartoonistHead);
        $this->assertEquals('cartoonist', $portal, 'Cartoonist Head must resolve to cartoonist portal, not section-head.');

        $staffPortal = $authController->resolvePortal($this->staffCartoonist);
        $this->assertEquals('cartoonist', $staffPortal);
    }

    public function test_cartoonist_head_can_access_cartoon_desk_and_endorse_cartoons()
    {
        $cartoon = ArticleImage::create([
            'uploader_id' => $this->staffCartoonist->id,
            'image_type' => 'cartoon',
            'file_url' => 'https://res.cloudinary.com/the-lens/image/upload/v1/test-cartoon.png',
            'title' => 'Editorial Cartoon 1',
            'status' => 'pending_head',
        ]);

        // Cartoonist Head accesses cartoon desk
        $deskRes = $this->actingAs($this->cartoonistHead)->getJson('/api/cartoonist/cartoon-desk');
        $deskRes->assertOk();
        $pending = collect($deskRes->json('pendingCartoons'));
        $this->assertTrue($pending->contains('id', $cartoon->id));

        // Cartoonist Head endorses cartoon to EIC
        $endorseRes = $this->actingAs($this->cartoonistHead)->postJson("/api/cartoonist/cartoons/{$cartoon->id}/endorse");
        $endorseRes->assertOk();
        $this->assertEquals('pending_eic', $cartoon->fresh()->status);
    }

    public function test_cartoonist_head_can_return_cartoon_for_revision_with_mandatory_remarks()
    {
        $cartoon = ArticleImage::create([
            'uploader_id' => $this->staffCartoonist->id,
            'image_type' => 'cartoon',
            'file_url' => 'https://res.cloudinary.com/the-lens/image/upload/v1/test-cartoon-2.png',
            'title' => 'Editorial Cartoon 2',
            'status' => 'pending_head',
        ]);

        // Remarks validation
        $invalidRes = $this->actingAs($this->cartoonistHead)->postJson("/api/cartoonist/cartoons/{$cartoon->id}/revision", []);
        $invalidRes->assertStatus(422);

        // Valid return with remarks
        $returnRes = $this->actingAs($this->cartoonistHead)->postJson("/api/cartoonist/cartoons/{$cartoon->id}/revision", [
            'remarks' => 'Please adjust linework contrast for print publication.',
        ]);
        $returnRes->assertOk();
        $fresh = $cartoon->fresh();
        $this->assertEquals('revision', $fresh->status);
        $this->assertEquals('Please adjust linework contrast for print publication.', $fresh->remarks);
    }

    public function test_manage_my_cartoons_scopes_only_to_owner_submissions(): void
    {
        // Cartoon created by Staff Cartoonist
        $staffCartoon = ArticleImage::create([
            'uploader_id' => $this->staffCartoonist->id,
            'image_type' => 'cartoon',
            'file_url' => 'https://res.cloudinary.com/the-lens/image/upload/v1/staff-cartoon.png',
            'title' => 'Staff Artwork',
            'status' => 'pending_head',
        ]);

        // Cartoon created by Cartoonist Head
        $headCartoon = ArticleImage::create([
            'uploader_id' => $this->cartoonistHead->id,
            'image_type' => 'cartoon',
            'file_url' => 'https://res.cloudinary.com/the-lens/image/upload/v1/head-cartoon.png',
            'title' => 'Head Artwork',
            'status' => 'pending_head',
        ]);

        // Head accesses Manage My Cartoons: must ONLY see headCartoon
        $headRes = $this->actingAs($this->cartoonistHead)->getJson('/api/cartoonist/manage-cartoons');
        $headRes->assertOk();
        $headItems = collect($headRes->json('cartoons'));
        $this->assertTrue($headItems->contains('id', $headCartoon->id));
        $this->assertFalse($headItems->contains('id', $staffCartoon->id));

        // Staff accesses Manage My Cartoons: must ONLY see staffCartoon
        $staffRes = $this->actingAs($this->staffCartoonist)->getJson('/api/cartoonist/manage-cartoons');
        $staffRes->assertOk();
        $staffItems = collect($staffRes->json('cartoons'));
        $this->assertTrue($staffItems->contains('id', $staffCartoon->id));
        $this->assertFalse($staffItems->contains('id', $headCartoon->id));
    }

    public function test_cartoonist_head_dashboard_returns_4_metrics_and_top_5_pending_cartoons(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            ArticleImage::create([
                'uploader_id' => $this->staffCartoonist->id,
                'image_type' => 'cartoon',
                'file_url' => "https://res.cloudinary.com/the-lens/image/upload/v1/cartoon-{$i}.png",
                'title' => "Cartoon {$i}",
                'status' => 'pending_head',
            ]);
        }

        ArticleImage::create([
            'uploader_id' => $this->staffCartoonist->id,
            'image_type' => 'cartoon',
            'file_url' => "https://res.cloudinary.com/the-lens/image/upload/v1/cartoon-rev.png",
            'title' => "Cartoon Revision",
            'status' => 'revision',
        ]);

        $res = $this->actingAs($this->cartoonistHead)->getJson('/api/cartoonist/dashboard');
        $res->assertOk();
        $metrics = $res->json('metrics');
        $this->assertArrayHasKey('published', $metrics);
        $this->assertArrayHasKey('pending', $metrics);
        $this->assertArrayHasKey('revision', $metrics);
        $this->assertArrayHasKey('library', $metrics);
        $this->assertEquals(7, $metrics['pending']);
        $this->assertEquals(1, $metrics['revision']);

        $cartoons = $res->json('cartoons');
        $this->assertCount(5, $cartoons);
    }

    public function test_cartoonist_submitting_cartoon_persists_custom_title_for_display(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->create('campus_sketch.png', 100, 'image/png');

        $res = $this->actingAs($this->staffCartoonist)->post('/api/cartoonist/submit-cartoon', [
            'cartoon_file' => $file,
            'title' => 'Campus Strike Editorial',
        ], ['Accept' => 'application/json']);

        $res->assertOk();

        $saved = ArticleImage::where('uploader_id', $this->staffCartoonist->id)
            ->where('title', 'Campus Strike Editorial')
            ->first();

        $this->assertNotNull($saved);
        $this->assertEquals('Campus Strike Editorial', $saved->title);
        $this->assertEquals('cartoon', $saved->image_type);
    }

    public function test_cartoonist_can_resubmit_cartoon_returned_for_revision(): void
    {
        Storage::fake('public');

        $cartoon = ArticleImage::create([
            'uploader_id' => $this->staffCartoonist->id,
            'image_type' => 'cartoon',
            'file_url' => 'https://res.cloudinary.com/the-lens/image/upload/v1/old-sketch.png',
            'title' => 'Old Sketch Title',
            'status' => 'revision',
            'remarks' => 'Please redo linework and add colors.',
        ]);

        $replacementFile = UploadedFile::fake()->create('revised_sketch.png', 150, 'image/png');

        $res = $this->actingAs($this->staffCartoonist)->postJson("/api/cartoonist/cartoons/{$cartoon->id}/resubmit", [
            'cartoon_file' => $replacementFile,
            'title' => 'Final Revised Cartoon',
        ]);

        $res->assertOk();
        $res->assertJson(['success' => true]);

        $fresh = $cartoon->fresh();
        $this->assertEquals('Final Revised Cartoon', $fresh->title);
        $this->assertEquals('pending', $fresh->status);
        $this->assertNull($fresh->remarks);
    }
}
