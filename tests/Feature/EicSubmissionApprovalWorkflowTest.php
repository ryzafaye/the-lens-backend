<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EicSubmissionApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $eic;
    protected User $aeic;
    protected User $president;
    protected User $vicePresident;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $eicRole = Role::firstOrCreate(['role_name' => 'Editor-in-Chief']);
        $aeicRole = Role::firstOrCreate(['role_name' => 'Associate Editor-in-Chief']);
        $presRole = Role::firstOrCreate(['role_name' => 'President']);
        $vpRole = Role::firstOrCreate(['role_name' => 'Vice President']);

        $this->eic = User::create([
            'role_id' => $eicRole->id,
            'full_name' => 'Elena EIC',
            'student_number' => '2024-88001-BN-0',
            'email' => 'elena_eic@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $this->aeic = User::create([
            'role_id' => $aeicRole->id,
            'full_name' => 'Arthur AEIC',
            'student_number' => '2024-88002-BN-0',
            'email' => 'arthur_aeic@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $this->president = User::create([
            'role_id' => $presRole->id,
            'full_name' => 'Patty President',
            'student_number' => '2024-88003-BN-0',
            'email' => 'patty_pres@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $this->vicePresident = User::create([
            'role_id' => $vpRole->id,
            'full_name' => 'Victor VP',
            'student_number' => '2024-88004-BN-0',
            'email' => 'victor_vp@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $this->category = Category::firstOrCreate(
            ['name' => 'News'],
            ['slug' => 'news']
        );
    }

    public function test_eic_submitting_article_routes_to_pending_president_review()
    {
        $response = $this->actingAs($this->eic)->postJson('/api/eic/submit-article', [
            'title' => 'EIC Major Investigative Scoop',
            'category_id' => $this->category->id,
            'excerpt' => 'An in-depth investigation into university campus developments.',
            'content' => '<p>Comprehensive investigative journalism piece written by the EIC.</p>',
            'status' => 'Pending Review',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Article submitted and forwarded to President for approval.',
            ]);

        $this->assertDatabaseHas('articles', [
            'title' => 'EIC Major Investigative Scoop',
            'user_id' => $this->eic->id,
            'status' => 'Pending President Review',
        ]);
    }

    public function test_eic_cannot_see_own_submission_in_eic_review_queue_or_pending_metrics()
    {
        $article = Article::create([
            'user_id' => $this->eic->id,
            'category_id' => $this->category->id,
            'title' => 'EIC Self Authored Story',
            'excerpt' => 'Excerpt test',
            'content' => '<p>Story content</p>',
            'status' => 'Pending President Review',
            'views' => 0,
            'shares' => 0,
        ]);

        // EIC Review Articles Endpoint
        $response = $this->actingAs($this->eic)->getJson('/api/eic/review-articles');
        $response->assertOk();
        $pendingArticles = collect($response->json('pendingArticles'));
        $this->assertFalse(
            $pendingArticles->contains('id', $article->id),
            'EIC review articles queue must never contain the EIC author\'s own submission.'
        );

        // EIC Dashboard Endpoint
        $dashResponse = $this->actingAs($this->eic)->getJson('/api/eic/dashboard');
        $dashResponse->assertOk();
        $dashPendingArticles = collect($dashResponse->json('pendingArticles'));
        $this->assertFalse(
            $dashPendingArticles->contains('id', $article->id),
            'EIC dashboard pending list must not display the EIC\'s own submission.'
        );
        $this->assertEquals(0, $dashResponse->json('metrics.pending'));
    }

    public function test_eic_cannot_approve_or_return_their_own_article()
    {
        $article = Article::create([
            'user_id' => $this->eic->id,
            'category_id' => $this->category->id,
            'title' => 'EIC Conflict Story',
            'excerpt' => 'Self approval attempt test',
            'content' => '<p>Content</p>',
            'status' => 'Pending EIC Review',
            'views' => 0,
            'shares' => 0,
        ]);

        // Self-approve attempt
        $approveResponse = $this->actingAs($this->eic)
            ->postJson("/api/eic/review-articles/{$article->id}/approve");
        $approveResponse->assertStatus(403);

        // Self-return attempt
        $returnResponse = $this->actingAs($this->eic)
            ->postJson("/api/eic/review-articles/{$article->id}/revision", [
                'remarks' => 'Returning my own article',
            ]);
        $returnResponse->assertStatus(403);
    }

    public function test_president_sees_eic_article_and_can_approve_it()
    {
        $article = Article::create([
            'user_id' => $this->eic->id,
            'category_id' => $this->category->id,
            'title' => 'EIC Landmark Story For Executive Approval',
            'excerpt' => 'Executive review excerpt',
            'content' => '<p>Article awaiting presidential review</p>',
            'status' => 'Pending President Review',
            'views' => 0,
            'shares' => 0,
        ]);

        // President review queue should display the article
        $reviewResponse = $this->actingAs($this->president)->getJson('/api/executive/review-articles');
        $reviewResponse->assertOk();
        $articles = collect($reviewResponse->json('pendingArticles'));
        $this->assertTrue(
            $articles->contains('id', $article->id),
            'President review queue must include articles in Pending President Review.'
        );

        // President can approve the article
        $approveResponse = $this->actingAs($this->president)
            ->postJson("/api/executive/review-articles/{$article->id}/approve");
        $approveResponse->assertOk();

        $this->assertEquals('Approved', $article->fresh()->status);
    }

    public function test_vice_president_does_not_see_or_approve_pending_president_review_article()
    {
        $article = Article::create([
            'user_id' => $this->eic->id,
            'category_id' => $this->category->id,
            'title' => 'Strictly President Review Story',
            'excerpt' => 'VP should not see this',
            'content' => '<p>Secret presidential manuscript</p>',
            'status' => 'Pending President Review',
            'views' => 0,
            'shares' => 0,
        ]);

        // VP review queue must NOT list Pending President Review
        $vpReviewResponse = $this->actingAs($this->vicePresident)->getJson('/api/executive/review-articles');
        $vpReviewResponse->assertOk();
        $articles = collect($vpReviewResponse->json('pendingArticles'));
        $this->assertFalse(
            $articles->contains('id', $article->id),
            'Vice President review queue must NOT include Pending President Review articles.'
        );

        // VP attempting to approve must receive 403 Forbidden
        $vpApproveResponse = $this->actingAs($this->vicePresident)
            ->postJson("/api/executive/review-articles/{$article->id}/approve");
        $vpApproveResponse->assertStatus(403);

        // VP attempting to return must receive 403 Forbidden
        $vpReturnResponse = $this->actingAs($this->vicePresident)
            ->postJson("/api/executive/review-articles/{$article->id}/revision", [
                'remarks' => 'VP returning president article',
            ]);
        $vpReturnResponse->assertStatus(403);
    }

    public function test_president_returning_eic_article_routes_to_for_writer_revision()
    {
        $article = Article::create([
            'user_id' => $this->eic->id,
            'category_id' => $this->category->id,
            'title' => 'EIC Story Needing Edits',
            'excerpt' => 'Needs polish',
            'content' => '<p>Draft that needs revision</p>',
            'status' => 'Pending President Review',
            'views' => 0,
            'shares' => 0,
        ]);

        $returnResponse = $this->actingAs($this->president)
            ->postJson("/api/executive/review-articles/{$article->id}/revision", [
                'remarks' => 'Please tighten the conclusion and verify the sources cited.',
            ]);

        $returnResponse->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Article returned to Editor-in-Chief for revision.',
            ]);

        $fresh = $article->fresh();
        $this->assertEquals('For Writer Revision', $fresh->status);
        $this->assertEquals('Please tighten the conclusion and verify the sources cited.', $fresh->remarks);
    }
}
