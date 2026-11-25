<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeactivatedAccountWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Role $writerRole;
    private Category $newsCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writerRole = Role::create([
            'role_name' => 'News Writer',
        ]);

        $this->newsCategory = Category::create([
            'name' => 'Campus News',
            'slug' => 'news',
        ]);
    }

    public function test_active_user_can_login_successfully(): void
    {
        $user = User::create([
            'role_id' => $this->writerRole->id,
            'full_name' => 'Active Writer',
            'student_number' => '2023-11111-BN-0',
            'email' => 'active@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $response = $this->postJson('/api/login', [
            'student_number' => '2023-11111-BN-0',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Login successful')
            ->assertJsonPath('user.full_name', 'Active Writer');

        $this->assertAuthenticatedAs($user);
    }

    public function test_deactivated_user_cannot_login_via_json_api(): void
    {
        $user = User::create([
            'role_id' => $this->writerRole->id,
            'full_name' => 'Deactivated Writer',
            'student_number' => '2023-22222-BN-0',
            'email' => 'deactivated@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'deactivated',
        ]);

        $response = $this->postJson('/api/login', [
            'student_number' => '2023-22222-BN-0',
            'password' => 'password123',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Your account has been deactivated. Please contact an administrator.')
            ->assertJsonPath('errors.student_number.0', 'Your account has been deactivated. Please contact an administrator.');

        $this->assertGuest();
    }

    public function test_deactivated_user_cannot_login_via_form_post(): void
    {
        User::create([
            'role_id' => $this->writerRole->id,
            'full_name' => 'Deactivated Writer',
            'student_number' => '2023-33333-BN-0',
            'email' => 'deactivated_web@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'deactivated',
        ]);

        $response = $this->post('/api/login', [
            'student_number' => '2023-33333-BN-0',
            'password' => 'password123',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('errors.student_number.0', 'Your account has been deactivated. Please contact an administrator.');

        $this->assertGuest();
    }

    public function test_deactivated_user_session_is_terminated_on_api_user_endpoint(): void
    {
        $deactivatedUser = User::create([
            'role_id' => $this->writerRole->id,
            'full_name' => 'Terminated Writer',
            'student_number' => '2023-44444-BN-0',
            'email' => 'terminated@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'deactivated',
        ]);

        $response = $this->actingAs($deactivatedUser)->getJson('/api/user');

        $response->assertStatus(403)
            ->assertJsonPath('user', null)
            ->assertJsonPath('message', 'Your account has been deactivated. Please contact an administrator.');

        $this->assertGuest();
    }

    public function test_deactivated_user_is_blocked_by_check_role_middleware(): void
    {
        $deactivatedUser = User::create([
            'role_id' => $this->writerRole->id,
            'full_name' => 'Blocked Writer',
            'student_number' => '2023-55555-BN-0',
            'email' => 'blocked@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'deactivated',
        ]);

        $response = $this->actingAs($deactivatedUser)->getJson('/api/writer/dashboard');

        $response->assertStatus(403);
        $this->assertGuest();
    }

    public function test_published_article_by_deactivated_user_is_accessible_to_public(): void
    {
        $deactivatedAuthor = User::create([
            'role_id' => $this->writerRole->id,
            'full_name' => 'Alumnus Author',
            'student_number' => '2020-99999-BN-0',
            'email' => 'alumnus@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'deactivated',
        ]);

        $article = Article::create([
            'user_id' => $deactivatedAuthor->id,
            'category_id' => $this->newsCategory->id,
            'title' => 'Historic Campus Investigation',
            'slug' => 'historic-campus-investigation',
            'excerpt' => 'Groundbreaking journalism from past semester.',
            'content' => 'Full archival investigative piece.',
            'status' => 'published',
            'is_archived' => false,
            'views' => 150,
            'shares' => 20,
            'published_at' => now()->subMonths(3),
        ]);

        // 1. Accessible via Public Article JSON API
        $articleResponse = $this->getJson('/api/public/article/historic-campus-investigation');
        $articleResponse->assertOk()
            ->assertJsonPath('props.article.title', 'Historic Campus Investigation')
            ->assertJsonPath('props.article.author.name', 'Alumnus Author')
            ->assertJsonPath('props.article.author.id', $deactivatedAuthor->id);

        // 2. Accessible via Public Author Profile JSON API
        $authorResponse = $this->getJson('/api/public/author/' . $deactivatedAuthor->id);
        $authorResponse->assertOk()
            ->assertJsonPath('props.authorName', 'Alumnus Author')
            ->assertJsonPath('props.articles.0.title', 'Historic Campus Investigation')
            ->assertJsonCount(1, 'props.articles');

        // 3. Accessible via Public Home Page Feed
        $homeResponse = $this->getJson('/api/public/home');
        $homeResponse->assertOk();
        $this->assertTrue(
            collect($homeResponse->json('props.latestArticles'))->contains('title', 'Historic Campus Investigation')
            || $homeResponse->json('props.featuredArticle.title') === 'Historic Campus Investigation'
        );

        // 4. Accessible via Public Archives Search
        $archivesResponse = $this->getJson('/api/public/archives?search=Historic');
        $archivesResponse->assertOk();
        $this->assertTrue(
            collect($archivesResponse->json('props.articles'))->contains('title', 'Historic Campus Investigation')
        );
    }
}
