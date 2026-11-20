<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SectionHeadReviewArticlesTest extends TestCase
{
    use RefreshDatabase;

    public function test_returned_article_for_revision_appears_in_section_head_revision_tab(): void
    {
        // 1. Roles
        $newsEditorRole = Role::firstOrCreate(['role_name' => 'News Editor']);
        $eicRole = Role::firstOrCreate(['role_name' => 'Editor-in-Chief']);
        $writerRole = Role::firstOrCreate(['role_name' => 'Writer']);

        // 2. Categories
        $newsCategory = Category::firstOrCreate(['name' => 'News', 'slug' => 'news']);
        $featureCategory = Category::firstOrCreate(['name' => 'Features', 'slug' => 'features']);
        $opinionCategory = Category::firstOrCreate(['name' => 'Opinion', 'slug' => 'opinion']);

        // 3. Users
        $newsEditor = User::create([
            'role_id' => $newsEditorRole->id,
            'student_number' => '2023-00001-BN-0',
            'full_name' => 'Jane News Editor',
            'email' => 'newshead@thelens.test',
            'password' => Hash::make('secret123'),
        ]);

        $eic = User::create([
            'role_id' => $eicRole->id,
            'student_number' => '2023-00002-BN-0',
            'full_name' => 'John EIC',
            'email' => 'eic@thelens.test',
            'password' => Hash::make('secret123'),
        ]);

        $writer = User::create([
            'role_id' => $writerRole->id,
            'student_number' => '2023-00003-BN-0',
            'full_name' => 'Bob Journalist',
            'email' => 'bob@thelens.test',
            'password' => Hash::make('secret123'),
        ]);

        // 4. Create an article under News currently 'Pending EIC Review'
        $newsArticle = Article::create([
            'user_id' => $writer->id,
            'category_id' => $newsCategory->id,
            'title' => 'Breaking News Across Campus',
            'excerpt' => 'An excerpt of breaking news.',
            'content' => '<p>Full content of the breaking news manuscript.</p>',
            'status' => 'Pending EIC Review',
        ]);

        // Create an article under Feature currently 'Pending EIC Review'
        $featureArticle = Article::create([
            'user_id' => $writer->id,
            'category_id' => $featureCategory->id,
            'title' => 'Student Life in 2026',
            'excerpt' => 'Feature excerpt.',
            'content' => '<p>Feature content.</p>',
            'status' => 'Pending EIC Review',
        ]);

        // Create an article under Opinion currently 'Pending EIC Review'
        $opinionArticle = Article::create([
            'user_id' => $writer->id,
            'category_id' => $opinionCategory->id,
            'title' => 'The Voice of Students',
            'excerpt' => 'Opinion excerpt.',
            'content' => '<p>Opinion content.</p>',
            'status' => 'Pending EIC Review',
        ]);

        // 5. EIC returns News and Feature articles for revision
        $eicResponse = $this->actingAs($eic)->postJson("/api/eic/review-articles/{$newsArticle->id}/revision", [
            'remarks' => 'Please verify source citations in paragraph 2.',
        ]);
        $eicResponse->assertStatus(200);

        $this->actingAs($eic)->postJson("/api/eic/review-articles/{$featureArticle->id}/revision", [
            'remarks' => 'Feature needs more emotional hook.',
        ]);

        // 5b. EIC returns Opinion article for revision
        $opinionResponse = $this->actingAs($eic)->postJson("/api/eic/review-articles/{$opinionArticle->id}/revision", [
            'remarks' => 'Opinion needs stronger argument.',
        ]);
        $opinionResponse->assertStatus(200);

        // Verify status in database:
        // News goes to Section Head -> 'For Head Revision'
        $this->assertEquals('For Head Revision', $newsArticle->fresh()->status);
        $this->assertEquals('Please verify source citations in paragraph 2.', $newsArticle->fresh()->remarks);

        // Opinion has NO section head -> routes directly to Writer as 'For Writer Revision'
        $this->assertEquals('For Writer Revision', $opinionArticle->fresh()->status);
        $this->assertEquals('Opinion needs stronger argument.', $opinionArticle->fresh()->remarks);

        // 6. News Editor fetches their review queue via Section Head API
        $sectionHeadResponse = $this->actingAs($newsEditor)->getJson('/api/section-head/review-articles');
        $sectionHeadResponse->assertStatus(200);

        $data = $sectionHeadResponse->json();
        $this->assertArrayHasKey('revisionArticles', $data);
        $this->assertArrayHasKey('pendingArticles', $data);

        // Strict category confinement: News Editor only receives News revision articles!
        $revArticles = $data['revisionArticles'];
        $this->assertCount(1, $revArticles);
        $this->assertEquals($newsArticle->id, $revArticles[0]['id']);
        $this->assertEquals('Breaking News Across Campus', $revArticles[0]['title']);
        $this->assertEquals('Please verify source citations in paragraph 2.', $revArticles[0]['remarks']);
        $this->assertEquals('For Head Revision', $revArticles[0]['status']);

        // 7. Writer checks their dashboard:
        // News article is currently with section head ('For Head Revision'),
        // so it is NOT put on pending review of writer, and writer only has 1 'for_revision' (the Opinion article)!
        $writerDash = $this->actingAs($writer)->getJson('/api/writer/dashboard');
        $writerDash->assertStatus(200);
        $this->assertEquals(0, $writerDash->json('writerStats.pending_review'));
        $this->assertEquals(1, $writerDash->json('writerStats.for_revision'));

        // Writer manage articles also does not put the News article on pending review
        $writerManage = $this->actingAs($writer)->getJson('/api/writer/manage-my-articles');
        $writerManage->assertStatus(200);
        $writerArticleIds = collect($writerManage->json('articles'))->pluck('id')->toArray();
        $this->assertNotContains($newsArticle->id, $writerArticleIds);

        // Writer cannot edit the News article while it is in 'For Head Revision'
        $submitCheck = $this->actingAs($writer)->getJson("/api/writer/submit-article?draft_id={$newsArticle->id}");
        $submitCheck->assertStatus(200);
        $this->assertNull($submitCheck->json('editingDraft'));

        // 8. News Editor determination: News Editor decides author must revise it
        // Section Head returns the article for revision
        $shReturn = $this->actingAs($newsEditor)->postJson("/api/section-head/review-articles/{$newsArticle->id}/revision", [
            'remarks' => 'Author, please rewrite paragraph 2 with primary sources.',
        ]);
        $shReturn->assertStatus(200);

        // Now, and ONLY now, status becomes 'For Writer Revision'
        $this->assertEquals('For Writer Revision', $newsArticle->fresh()->status);
        $this->assertEquals('Author, please rewrite paragraph 2 with primary sources.', $newsArticle->fresh()->remarks);

        // It is now gone from Section Head's revision tab
        $refreshedSH = $this->actingAs($newsEditor)->getJson('/api/section-head/review-articles');
        $this->assertCount(0, $refreshedSH->json()['revisionArticles']);

        // And Writer can now see it in manage articles and edit it
        $writerSubmitNow = $this->actingAs($writer)->getJson("/api/writer/submit-article?draft_id={$newsArticle->id}");
        $writerSubmitNow->assertStatus(200);
        $this->assertNotNull($writerSubmitNow->json('editingDraft'));
        $this->assertEquals($newsArticle->id, $writerSubmitNow->json('editingDraft.id'));
    }
}
