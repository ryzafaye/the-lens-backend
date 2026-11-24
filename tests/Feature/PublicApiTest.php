<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['id' => 1], [
            'role_name' => 'Writer',
            'dashboard_route' => 'writer.dashboard',
        ]);

        $this->user = User::create([
            'role_id' => $role->id,
            'full_name' => 'Jane Journalist',
            'student_number' => '2023-00099-BN-0',
            'email' => 'jane@lens.test',
            'password' => bcrypt('password123'),
        ]);

        $this->category = Category::firstOrCreate(['id' => 1], [
            'name' => 'News',
            'slug' => 'news',
        ]);

        $this->article = Article::create([
            'title' => 'Campus Wins National Debate Cup',
            'slug' => 'campus-wins-national-debate-cup',
            'excerpt' => 'Biñan campus debate team secured top honors.',
            'content' => '<p>Biñan campus debate team secured top honors at the national championships.</p>',
            'status' => 'Published',
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'is_archived' => false,
            'views' => 15,
            'shares' => 3,
            'published_at' => now(),
        ]);
    }

    public function test_public_home_api_returns_expected_structure(): void
    {
        $response = $this->getJson('/api/public/home');
        $response->assertOk()
            ->assertJsonStructure([
                'props' => [
                    'featuredArticle',
                    'latestArticles',
                    'moreStories',
                    'categorySections',
                ],
            ]);
    }

    public function test_public_category_api_returns_category_articles(): void
    {
        $response = $this->getJson('/api/public/category/news');
        $response->assertOk()
            ->assertJsonStructure([
                'props' => [
                    'currentCategory' => ['id', 'name', 'slug', 'description'],
                    'articles',
                    'pagination',
                    'total',
                ],
            ]);
        $this->assertEquals('News', $response->json('props.currentCategory.name'));
    }

    public function test_public_category_latest_returns_articles(): void
    {
        $response = $this->getJson('/api/public/category/latest');
        $response->assertOk()
            ->assertJsonPath('props.currentCategory.slug', 'latest');
    }

    public function test_public_article_api_returns_article_and_increments_views(): void
    {
        $initialViews = $this->article->views;
        $response = $this->getJson('/api/public/article/' . $this->article->slug);
        $response->assertOk()
            ->assertJsonPath('props.article.title', 'Campus Wins National Debate Cup');

        $this->assertEquals($initialViews + 1, $this->article->fresh()->views);
    }

    public function test_public_article_share_increments_shares(): void
    {
        $initialShares = $this->article->shares;
        $response = $this->postJson('/api/public/article/' . $this->article->id . '/share');
        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertEquals($initialShares + 1, $this->article->fresh()->shares);
    }

    public function test_public_archives_api_returns_filtered_articles(): void
    {
        $response = $this->getJson('/api/public/archives?category=News');
        $response->assertOk()
            ->assertJsonStructure([
                'props' => [
                    'articles',
                    'pagination',
                    'categories',
                    'filters',
                    'total',
                ],
            ]);
    }

    public function test_public_author_api_returns_portfolio(): void
    {
        $response = $this->getJson('/api/public/author/' . $this->user->id);
        $response->assertOk()
            ->assertJsonPath('props.authorName', 'Jane Journalist')
            ->assertJsonCount(1, 'props.articles');
    }

    public function test_public_feedback_submission_persists_and_returns_json(): void
    {
        $response = $this->postJson('/api/public/feedback', [
            'name' => 'Campus Reader',
            'subject' => 'Great Coverage',
            'message' => 'Keep up the amazing journalistic work!',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('feedback', [
            'name' => 'Campus Reader',
            'subject' => 'Great Coverage',
        ]);
    }

    public function test_public_officers_api_returns_safe_officers_list(): void
    {
        $presRole = Role::create([
            'role_name' => 'President',
        ]);

        $vpRole = Role::create([
            'role_name' => 'Executive Vice President',
        ]);

        User::create([
            'role_id' => $vpRole->id,
            'full_name' => 'VP Officer',
            'student_number' => '2023-00002-BN-0',
            'email' => 'vp@lens.test',
            'password' => bcrypt('secret123'),
            'account_status' => 'active',
        ]);

        User::create([
            'role_id' => $presRole->id,
            'full_name' => 'President Officer',
            'student_number' => '2023-00001-BN-0',
            'email' => 'president@lens.test',
            'password' => bcrypt('secret123'),
            'account_status' => 'active',
        ]);

        $response = $this->getJson('/api/public/officers');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('count', 2);

        $officers = $response->json('officers');
        $this->assertCount(2, $officers);

        // Verify correct hierarchy sort order: President (1) before EVP (2)
        $this->assertSame('President Officer', $officers[0]['name']);
        $this->assertSame('President', $officers[0]['role']);
        $this->assertSame(1, $officers[0]['sort_order']);
        $this->assertSame('VP Officer', $officers[1]['name']);
        $this->assertSame('Executive Vice President', $officers[1]['role']);
        $this->assertSame(2, $officers[1]['sort_order']);

        // Strictly verify NO sensitive fields leak
        foreach ($officers as $officer) {
            $this->assertArrayNotHasKey('email', $officer);
            $this->assertArrayNotHasKey('password', $officer);
            $this->assertArrayNotHasKey('student_number', $officer);
            $this->assertArrayNotHasKey('studentNumber', $officer);
            $this->assertArrayNotHasKey('remember_token', $officer);
            $this->assertArrayHasKey('name', $officer);
            $this->assertArrayHasKey('role', $officer);
            $this->assertArrayHasKey('board', $officer);
            $this->assertArrayHasKey('profile_picture_url', $officer);
        }

        // Also test the /api/pages/public/officers route
        $pagesResponse = $this->getJson('/api/pages/public/officers');
        $pagesResponse->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_cors_allows_configured_frontend_url_and_supports_credentials(): void
    {
        config(['cors.allowed_origins' => array_merge(config('cors.allowed_origins', []), ['https://the-lens.vercel.app'])]);

        $response = $this->withHeaders([
            'Origin' => 'https://the-lens.vercel.app',
            'Access-Control-Request-Method' => 'GET',
        ])->optionsJson('/api/public/home');

        $response->assertStatus(204)
            ->assertHeader('Access-Control-Allow-Origin', 'https://the-lens.vercel.app')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }
}
