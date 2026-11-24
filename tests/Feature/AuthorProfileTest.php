<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_profile_api_returns_streamlined_author_portfolio(): void
    {
        $role = Role::create([
            'role_name' => 'Literary Head',
        ]);

        $author = User::create([
            'role_id' => $role->id,
            'full_name' => 'Arabella Mae D. Andal',
            'student_number' => '2023-00001-BN-0',
            'email' => 'arabella@lens.test',
            'password' => bcrypt('secret123'),
        ]);

        $category = Category::create([
            'name' => 'Literature',
            'slug' => 'literature',
        ]);

        Article::create([
            'user_id' => $author->id,
            'category_id' => $category->id,
            'title' => 'Awit ng mga Tala',
            'slug' => 'awit-ng-mga-tala',
            'excerpt' => 'Anthology of student verses.',
            'content' => 'Full article content here.',
            'status' => 'published',
            'is_archived' => false,
            'views' => 4,
            'shares' => 0,
            'published_at' => now(),
        ]);

        $response = $this->getJson('/api/public/author/' . $author->id);

        $response->assertStatus(200);
        $response->assertJsonPath('props.authorName', 'Arabella Mae D. Andal');
        $response->assertJsonPath('props.roleName', 'Literary Head');
        $response->assertJsonPath('props.articles.0.title', 'Awit ng mga Tala');
        $response->assertJsonCount(1, 'props.articles');
    }

    public function test_author_profile_api_handles_author_with_no_articles(): void
    {
        $role = Role::create([
            'role_name' => 'Staff Contributor',
        ]);

        $author = User::create([
            'role_id' => $role->id,
            'full_name' => 'Marcus Cruz',
            'student_number' => '2023-00002-BN-0',
            'email' => 'marcus@lens.test',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->getJson('/api/public/author/' . $author->id);

        $response->assertStatus(200);
        $response->assertJsonPath('props.authorName', 'Marcus Cruz');
        $response->assertJsonCount(0, 'props.articles');
    }
}
