<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PerformanceOptimizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_avatar_url_optimizes_cloudinary_url(): void
    {
        $role = Role::firstOrCreate(['role_name' => 'Writer']);
        $user = User::create([
            'role_id' => $role->id,
            'student_number' => '2023-00001-BN-0',
            'full_name' => 'Test Writer',
            'email' => 'writer@test.com',
            'password' => Hash::make('password123'),
            'profile_picture_url' => 'https://res.cloudinary.com/test-cloud/image/upload/v12345/the-lens/avatars/user.jpg',
        ]);

        $this->assertStringContainsString('f_auto,q_auto', $user->avatar_url);
        $this->assertStringContainsString('w_300', $user->avatar_url);
        $this->assertStringContainsString('the-lens/avatars/user.jpg', $user->avatar_url);
    }

    public function test_article_caches_are_cleared_on_save_and_delete(): void
    {
        $role = Role::firstOrCreate(['role_name' => 'Writer']);
        $user = User::create([
            'role_id' => $role->id,
            'student_number' => '2023-00002-BN-0',
            'full_name' => 'Writer Two',
            'email' => 'writer2@test.com',
            'password' => Hash::make('password123'),
        ]);

        $category = Category::create([
            'name' => 'News',
            'slug' => 'news',
        ]);

        // Prime cache
        Cache::put('public_home_data_web', ['cached' => true], 600);
        Cache::put('public_home_data_api', ['cached' => true], 600);
        Cache::put('writer_manage_articles_' . $user->id, ['cached' => true], 600);

        $this->assertTrue(Cache::has('public_home_data_web'));
        $this->assertTrue(Cache::has('public_home_data_api'));
        $this->assertTrue(Cache::has('writer_manage_articles_' . $user->id));

        // Creating article should invalidate caches
        $article = Article::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => 'Breaking News',
            'excerpt' => 'Short summary of breaking news',
            'content' => '<p>Full content of breaking news</p>',
            'status' => 'Published',
            'published_at' => now(),
        ]);

        $this->assertFalse(Cache::has('public_home_data_web'));
        $this->assertFalse(Cache::has('public_home_data_api'));
        $this->assertFalse(Cache::has('writer_manage_articles_' . $user->id));

        // Re-prime and test delete
        Cache::put('public_home_data_web', ['cached' => true], 600);
        Cache::put('writer_manage_articles_' . $user->id, ['cached' => true], 600);

        $article->delete();

        $this->assertFalse(Cache::has('public_home_data_web'));
        $this->assertFalse(Cache::has('writer_manage_articles_' . $user->id));
    }

    public function test_category_caches_are_cleared_on_save_and_delete(): void
    {
        Cache::put('public_categories_all', ['cached' => true], 600);
        Cache::put('public_home_data_web', ['cached' => true], 600);

        $category = Category::create([
            'name' => 'Opinion',
            'slug' => 'opinion',
        ]);

        $this->assertFalse(Cache::has('public_categories_all'));
        $this->assertFalse(Cache::has('public_home_data_web'));

        Cache::put('public_categories_all', ['cached' => true], 600);
        $category->delete();
        $this->assertFalse(Cache::has('public_categories_all'));
    }

    public function test_public_home_api_caches_and_returns_payload(): void
    {
        $role = Role::firstOrCreate(['role_name' => 'Writer']);
        $user = User::create([
            'role_id' => $role->id,
            'student_number' => '2023-00003-BN-0',
            'full_name' => 'Writer Three',
            'email' => 'writer3@test.com',
            'password' => Hash::make('password123'),
        ]);

        $category = Category::create([
            'name' => 'Feature',
            'slug' => 'feature',
        ]);

        Article::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => 'Campus Feature Story',
            'excerpt' => 'Brief overview of campus feature',
            'content' => '<p>Long article content here</p>',
            'status' => 'Published',
            'published_at' => now(),
        ]);

        $response = $this->getJson('/api/pages/public/home');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'props' => [
                    'featuredArticle',
                    'latestArticles',
                    'moreStories',
                    'categorySections',
                ],
            ]);

        $this->assertTrue(Cache::has('public_home_data_api'));
        $this->assertTrue(Cache::has('public_categories_all'));
    }

    public function test_section_head_dashboard_includes_article_content_and_avatar(): void
    {
        $shRole = Role::firstOrCreate(['role_name' => 'News Section Head']);
        $sectionHead = User::create([
            'role_id' => $shRole->id,
            'student_number' => '2023-00010-BN-0',
            'full_name' => 'News Section Head',
            'email' => 'sectionhead@test.com',
            'password' => Hash::make('password123'),
        ]);

        $writerRole = Role::firstOrCreate(['role_name' => 'Writer']);
        $writer = User::create([
            'role_id' => $writerRole->id,
            'student_number' => '2023-00011-BN-0',
            'full_name' => 'Staff Writer',
            'email' => 'writer11@test.com',
            'password' => Hash::make('password123'),
            'profile_picture_url' => 'https://res.cloudinary.com/test-cloud/image/upload/v12345/the-lens/avatars/writer.jpg',
        ]);

        $category = Category::create([
            'name' => 'News',
            'slug' => 'news',
        ]);

        $pending = Article::create([
            'user_id' => $writer->id,
            'category_id' => $category->id,
            'title' => 'Pending Review News',
            'excerpt' => 'Pending excerpt',
            'content' => '<p>Detailed manuscript body content</p>',
            'status' => 'Pending Review',
        ]);

        $published = Article::create([
            'user_id' => $writer->id,
            'category_id' => $category->id,
            'title' => 'Published News Story',
            'excerpt' => 'Published excerpt',
            'content' => '<p>Full published story text body</p>',
            'status' => 'Published',
            'views' => 15,
            'published_at' => now(),
        ]);

        $response = $this->actingAs($sectionHead)->getJson('/api/section-head/dashboard');
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertNotEmpty($data['pendingArticles']);
        $this->assertEquals('Detailed manuscript body content', $data['pendingArticles'][0]['content']);
        $this->assertNotNull($data['pendingArticles'][0]['author_avatar']);

        $this->assertNotEmpty($data['publishedArticles']);
        $this->assertEquals('Full published story text body', $data['publishedArticles'][0]['content']);
        $this->assertNotNull($data['publishedArticles'][0]['author_avatar']);
    }

    public function test_profile_update_does_not_log_to_activity_logs(): void
    {
        $role = Role::firstOrCreate(['role_name' => 'Writer']);
        $user = User::create([
            'role_id' => $role->id,
            'student_number' => '2023-00012-BN-0',
            'full_name' => 'Writer Profile Tester',
            'email' => 'profiletester@test.com',
            'password' => Hash::make('password123'),
        ]);

        // 1. Direct call to logAction with profile or password action should be blocked
        \App\Models\ActivityLog::logAction($user->id, 'updated profile information');
        \App\Models\ActivityLog::logAction($user->id, 'updated profile picture');
        \App\Models\ActivityLog::logAction($user->id, 'removed profile picture');
        \App\Models\ActivityLog::logAction($user->id, 'updated account password');

        $this->assertEquals(0, \App\Models\ActivityLog::count());

        // 2. HTTP profile update should succeed without creating activity log
        $response = $this->actingAs($user)->postJson('/api/profile/update-info', [
            'full_name' => 'Updated Writer',
            'student_number' => '2023-00012-BN-0',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(0, \App\Models\ActivityLog::count());
    }
}
