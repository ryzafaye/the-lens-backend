<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ArticleWorkflowOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected User $sectionHead;
    protected User $eic;
    protected User $aeic;
    protected User $president;
    protected User $vicePresident;
    protected Category $newsCategory;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $shRole = Role::firstOrCreate(['role_name' => 'News Head']);
        $eicRole = Role::firstOrCreate(['role_name' => 'Editor-in-Chief']);
        $aeicRole = Role::firstOrCreate(['role_name' => 'Associate Editor-in-Chief']);
        $presRole = Role::firstOrCreate(['role_name' => 'President']);
        $vpRole = Role::firstOrCreate(['role_name' => 'Vice President']);

        $this->sectionHead = User::create([
            'role_id' => $shRole->id,
            'full_name' => 'News Head Owner',
            'student_number' => '2024-99001-BN-0',
            'email' => 'newshead_owner@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $this->eic = User::create([
            'role_id' => $eicRole->id,
            'full_name' => 'EIC Owner',
            'student_number' => '2024-99002-BN-0',
            'email' => 'eic_owner@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $this->aeic = User::create([
            'role_id' => $aeicRole->id,
            'full_name' => 'AEIC Reviewer',
            'student_number' => '2024-99003-BN-0',
            'email' => 'aeic_reviewer@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $this->president = User::create([
            'role_id' => $presRole->id,
            'full_name' => 'President Owner',
            'student_number' => '2024-99004-BN-0',
            'email' => 'pres_owner@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $this->vicePresident = User::create([
            'role_id' => $vpRole->id,
            'full_name' => 'VP Owner',
            'student_number' => '2024-99005-BN-0',
            'email' => 'vp_owner@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $this->newsCategory = Category::firstOrCreate(['name' => 'News']);
    }

    public function test_section_head_cannot_see_or_approve_own_article_in_review_workflow(): void
    {
        $ownPending = Article::create([
            'user_id' => $this->sectionHead->id,
            'category_id' => $this->newsCategory->id,
            'title' => 'SH Own Pending Article',
            'excerpt' => 'Excerpt',
            'content' => '<p>Body</p>',
            'status' => 'Pending Head Review',
        ]);

        $ownRevision = Article::create([
            'user_id' => $this->sectionHead->id,
            'category_id' => $this->newsCategory->id,
            'title' => 'SH Own Revision Article',
            'excerpt' => 'Excerpt',
            'content' => '<p>Body</p>',
            'status' => 'For Head Revision',
        ]);

        $dashRes = $this->actingAs($this->sectionHead)->getJson('/api/section-head/dashboard');
        $dashRes->assertStatus(200);
        $dashIds = collect($dashRes->json('pendingArticles'))->pluck('id')->all();
        $this->assertNotContains((string) $ownPending->id, $dashIds);
        $this->assertNotContains((string) $ownRevision->id, $dashIds);

        $revRes = $this->actingAs($this->sectionHead)->getJson('/api/section-head/review-articles');
        $revRes->assertStatus(200);
        $pendingIds = collect($revRes->json('pendingArticles'))->pluck('id')->all();
        $revisionIds = collect($revRes->json('revisionArticles'))->pluck('id')->all();
        $this->assertNotContains((string) $ownPending->id, $pendingIds);
        $this->assertNotContains((string) $ownRevision->id, $revisionIds);

        $this->actingAs($this->sectionHead)
            ->postJson("/api/section-head/review-articles/{$ownPending->id}/approve")
            ->assertStatus(403);

        $this->actingAs($this->sectionHead)
            ->postJson("/api/section-head/review-articles/{$ownPending->id}/revision", ['remarks' => 'Self check'])
            ->assertStatus(403);

        $this->actingAs($this->sectionHead)
            ->postJson("/api/section-head/review-articles/{$ownPending->id}", [
                'title' => 'Edited',
                'content' => '<p>Edited</p>',
            ])
            ->assertStatus(403);

        $this->assertEquals('Pending Head Review', $ownPending->fresh()->status);
    }

    public function test_eic_cannot_see_or_approve_own_article_in_review_workflow(): void
    {
        $ownPending = Article::create([
            'user_id' => $this->eic->id,
            'category_id' => $this->newsCategory->id,
            'title' => 'EIC Own Pending Article',
            'excerpt' => 'Excerpt',
            'content' => '<p>Body</p>',
            'status' => 'Pending EIC Review',
        ]);

        $ownRevision = Article::create([
            'user_id' => $this->eic->id,
            'category_id' => $this->newsCategory->id,
            'title' => 'EIC Own Revision Article',
            'excerpt' => 'Excerpt',
            'content' => '<p>Body</p>',
            'status' => 'For Writer Revision',
        ]);

        $dashRes = $this->actingAs($this->eic)->getJson('/api/eic/dashboard');
        $dashRes->assertStatus(200);
        $dashIds = collect($dashRes->json('pendingArticles'))->pluck('id')->all();
        $this->assertNotContains((string) $ownPending->id, $dashIds);
        $this->assertNotContains((string) $ownRevision->id, $dashIds);

        $revRes = $this->actingAs($this->eic)->getJson('/api/eic/review-articles');
        $revRes->assertStatus(200);
        $pendingIds = collect($revRes->json('pendingArticles'))->pluck('id')->all();
        $this->assertNotContains($ownPending->id, $pendingIds);

        $this->actingAs($this->eic)
            ->postJson("/api/eic/review-articles/{$ownPending->id}/approve")
            ->assertStatus(403);

        $this->actingAs($this->eic)
            ->postJson("/api/eic/review-articles/{$ownPending->id}/revision", ['remarks' => 'Self check'])
            ->assertStatus(403);

        $this->actingAs($this->eic)
            ->postJson("/api/eic/review-articles/{$ownPending->id}", [
                'title' => 'Edited',
                'content' => '<p>Edited</p>',
            ])
            ->assertStatus(403);

        $this->assertEquals('Pending EIC Review', $ownPending->fresh()->status);
    }

    public function test_president_and_vp_cannot_see_or_approve_own_articles_but_other_reviewers_can(): void
    {
        $presArticle = Article::create([
            'user_id' => $this->president->id,
            'category_id' => $this->newsCategory->id,
            'title' => 'President Own Article',
            'excerpt' => 'Excerpt',
            'content' => '<p>Body</p>',
            'status' => 'Pending EIC Review',
        ]);

        $vpArticle = Article::create([
            'user_id' => $this->vicePresident->id,
            'category_id' => $this->newsCategory->id,
            'title' => 'VP Own Article',
            'excerpt' => 'Excerpt',
            'content' => '<p>Body</p>',
            'status' => 'Pending EIC Review',
        ]);

        // President does not see own article on dashboard or review queue
        $presDash = $this->actingAs($this->president)->getJson('/api/executive/dashboard');
        $presDash->assertStatus(200);
        $presDashIds = collect($presDash->json('pendingArticles'))->pluck('id')->all();
        $this->assertNotContains((string) $presArticle->id, $presDashIds);
        $this->assertContains((string) $vpArticle->id, $presDashIds);

        $presRev = $this->actingAs($this->president)->getJson('/api/executive/review-articles');
        $presRev->assertStatus(200);
        $presRevIds = collect($presRev->json('pendingArticles'))->pluck('id')->all();
        $this->assertNotContains($presArticle->id, $presRevIds);
        $this->assertContains($vpArticle->id, $presRevIds);

        // President cannot approve/revise/update own article
        $this->actingAs($this->president)
            ->postJson("/api/executive/review-articles/{$presArticle->id}/approve")
            ->assertStatus(403);
        $this->actingAs($this->president)
            ->postJson("/api/executive/review-articles/{$presArticle->id}/revision", ['remarks' => 'Self'])
            ->assertStatus(403);
        $this->actingAs($this->president)
            ->postJson("/api/executive/review-articles/{$presArticle->id}", [
                'title' => 'Edited',
                'content' => '<p>Edited</p>',
            ])
            ->assertStatus(403);

        // VP does not see own article on dashboard or review queue
        $vpDash = $this->actingAs($this->vicePresident)->getJson('/api/executive/dashboard');
        $vpDash->assertStatus(200);
        $vpDashIds = collect($vpDash->json('pendingArticles'))->pluck('id')->all();
        $this->assertNotContains((string) $vpArticle->id, $vpDashIds);
        $this->assertContains((string) $presArticle->id, $vpDashIds);

        $vpRev = $this->actingAs($this->vicePresident)->getJson('/api/executive/review-articles');
        $vpRev->assertStatus(200);
        $vpRevIds = collect($vpRev->json('pendingArticles'))->pluck('id')->all();
        $this->assertNotContains($vpArticle->id, $vpRevIds);
        $this->assertContains($presArticle->id, $vpRevIds);

        // VP cannot approve own article
        $this->actingAs($this->vicePresident)
            ->postJson("/api/executive/review-articles/{$vpArticle->id}/approve")
            ->assertStatus(403);

        // Another reviewer (EIC) CAN approve President's article
        $this->actingAs($this->eic)
            ->postJson("/api/eic/review-articles/{$presArticle->id}/approve")
            ->assertStatus(200);
        $this->assertEquals('Approved', $presArticle->fresh()->status);

        // President CAN approve VP's article
        $this->actingAs($this->president)
            ->postJson("/api/executive/review-articles/{$vpArticle->id}/approve")
            ->assertStatus(200);
        $this->assertEquals('Approved', $vpArticle->fresh()->status);
    }
}
