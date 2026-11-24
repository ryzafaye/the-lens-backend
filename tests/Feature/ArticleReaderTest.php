<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_api_returns_article_details_and_increments_views(): void
    {
        $role = Role::create([
            'role_name' => 'Writer',
        ]);

        $author = User::create([
            'role_id' => $role->id,
            'full_name' => 'Sofia M. Alcaraz',
            'student_number' => '2023-00003-BN-0',
            'email' => 'sofia@lens.test',
            'password' => bcrypt('secret123'),
        ]);

        $category = Category::create([
            'name' => 'Literature',
            'slug' => 'literature',
        ]);

        $article = Article::create([
            'user_id' => $author->id,
            'category_id' => $category->id,
            'title' => 'SPOOKTOBER | Manika, Manahimik Ka',
            'slug' => 'spooktober-manika-manahimik-ka',
            'excerpt' => 'Manika, Manahimik ka',
            'content' => '<p>May tanong ako sa inyo. Kapag may mambabarang na gustong manakit...</p>',
            'status' => 'published',
            'is_archived' => false,
            'views' => 14,
            'shares' => 2,
            'published_at' => now(),
        ]);

        $response = $this->getJson('/api/public/article/' . $article->slug);

        $response->assertStatus(200);
        $response->assertJsonPath('props.article.title', 'SPOOKTOBER | Manika, Manahimik Ka');
        $response->assertJsonPath('props.article.slug', 'spooktober-manika-manahimik-ka');
        $response->assertJsonPath('props.article.excerpt', 'Manika, Manahimik ka');
        $response->assertJsonPath('props.article.author.name', 'Sofia M. Alcaraz');
        $this->assertStringContainsString('May tanong ako sa inyo', $response->json('props.article.content'));

        $this->assertEquals(15, $article->fresh()->views);
    }
}
