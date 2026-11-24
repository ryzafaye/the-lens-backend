<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardRevisionArticlesWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles
        Role::create(['role_name' => 'Writer', 'role_description' => 'Writer']);
        Role::create(['role_name' => 'News Head', 'role_description' => 'News Section Head']);
        Role::create(['role_name' => 'Editor-in-Chief', 'role_description' => 'EIC']);
        Role::create(['role_name' => 'President', 'role_description' => 'President']);
    }

    public function test_section_head_dashboard_includes_revision_articles_with_remarks()
    {
        $category = Category::create(['name' => 'News', 'slug' => 'news']);
        $writerRole = Role::where('role_name', 'Writer')->first();
        $headRole = Role::where('role_name', 'News Head')->first();

        $head = User::create([
            'role_id' => $headRole->id,
            'full_name' => 'News Section Head',
            'student_number' => '2024-00101-BN-0',
            'email' => 'head@lens.test',
            'password' => bcrypt('password123'),
        ]);

        $writer = User::create([
            'role_id' => $writerRole->id,
            'full_name' => 'News Writer',
            'student_number' => '2024-00102-BN-0',
            'email' => 'writer@lens.test',
            'password' => bcrypt('password123'),
        ]);

        $article = Article::create([
            'user_id' => $writer->id,
            'category_id' => $category->id,
            'title' => 'Article For Revision',
            'slug' => 'article-for-revision',
            'excerpt' => 'Needs revision excerpt',
            'content' => '<p>Original body that needs revision</p>',
            'status' => 'For Writer Revision',
            'remarks' => 'Please update second paragraph with statistics.',
        ]);

        $response = $this->actingAs($head)->getJson('/api/section-head/dashboard');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertGreaterThanOrEqual(1, $data['metrics']['pending']);
        $this->assertCount(1, $data['pendingArticles']);
        $this->assertEquals($article->title, $data['pendingArticles'][0]['title']);
        $this->assertEquals('For Writer Revision', $data['pendingArticles'][0]['status']);
        $this->assertEquals('Please update second paragraph with statistics.', $data['pendingArticles'][0]['remarks']);
    }

    public function test_cannot_resubmit_revision_article_if_body_is_unmodified()
    {
        $category = Category::create(['name' => 'News', 'slug' => 'news']);
        $writerRole = Role::where('role_name', 'Writer')->first();
        $writer = User::create([
            'role_id' => $writerRole->id,
            'full_name' => 'News Writer',
            'student_number' => '2024-00103-BN-0',
            'email' => 'writer2@lens.test',
            'password' => bcrypt('password123'),
        ]);

        $article = Article::create([
            'user_id' => $writer->id,
            'category_id' => $category->id,
            'title' => 'Article Returned For Revision',
            'slug' => 'article-returned-for-revision',
            'excerpt' => 'Excerpt test',
            'content' => '<p>Original unmodified body text.</p>',
            'status' => 'For Writer Revision',
            'remarks' => 'Please revise manuscript content.',
        ]);

        // Attempting to resubmit with unmodified body (even with extra tags/whitespace)
        $response = $this->actingAs($writer)->postJson("/api/articles/{$article->id}/resubmit", [
            'title' => 'Article Returned For Revision',
            'content' => '<div>Original unmodified body text.</div>',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'You cannot submit an article returned for revision without editing the article body. Please revise the manuscript before submitting.',
        ]);

        // Verify status was NOT changed
        $this->assertEquals('For Writer Revision', $article->fresh()->status);
    }

    public function test_can_resubmit_revision_article_when_body_is_modified()
    {
        $category = Category::create(['name' => 'News', 'slug' => 'news']);
        $writerRole = Role::where('role_name', 'Writer')->first();
        $writer = User::create([
            'role_id' => $writerRole->id,
            'full_name' => 'News Writer',
            'student_number' => '2024-00104-BN-0',
            'email' => 'writer3@lens.test',
            'password' => bcrypt('password123'),
        ]);

        $article = Article::create([
            'user_id' => $writer->id,
            'category_id' => $category->id,
            'title' => 'Article Returned For Revision',
            'slug' => 'article-returned-for-revision-2',
            'excerpt' => 'Excerpt test',
            'content' => '<p>Original body text.</p>',
            'status' => 'For Writer Revision',
            'remarks' => 'Please revise manuscript content.',
        ]);

        // Resubmit with meaningfully modified body
        $response = $this->actingAs($writer)->postJson("/api/articles/{$article->id}/resubmit", [
            'title' => 'Updated Article Title',
            'content' => '<p>Original body text with new revised paragraphs and newly cited facts.</p>',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $refreshed = $article->fresh();
        $this->assertEquals('Pending Head Review', $refreshed->status);
        $this->assertNull($refreshed->remarks);
    }

    public function test_store_article_blocks_revision_article_if_body_is_unmodified()
    {
        $category = Category::create(['name' => 'News', 'slug' => 'news']);
        $writerRole = Role::where('role_name', 'Writer')->first();
        $writer = User::create([
            'role_id' => $writerRole->id,
            'full_name' => 'News Writer',
            'student_number' => '2024-00105-BN-0',
            'email' => 'writer4@lens.test',
            'password' => bcrypt('password123'),
        ]);

        $article = Article::create([
            'user_id' => $writer->id,
            'category_id' => $category->id,
            'title' => 'My Draft for Revision',
            'slug' => 'my-draft-for-revision',
            'excerpt' => 'Some excerpt',
            'content' => '<p>Unmodified body from revision feedback.</p>',
            'status' => 'For Writer Revision',
            'remarks' => 'Needs extensive body edits.',
        ]);

        // Submit via writer submit-article endpoint without editing body
        $response = $this->actingAs($writer)->postJson('/api/writer/submit-article', [
            'article_id' => $article->id,
            'title' => 'My Draft for Revision',
            'category_id' => $category->id,
            'excerpt' => 'Some excerpt',
            'content' => '<p>Unmodified body from revision feedback.</p>',
            'status' => 'Pending Review',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'You cannot submit an article returned for revision without editing the article body. Please revise the manuscript before submitting.',
        ]);
    }
}
