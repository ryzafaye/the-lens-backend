<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Article;
use App\Models\User;
use App\Models\Category;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ArticleCleanTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_article_body_text_removes_div_br_and_html_tags(): void
    {
        $dirty1 = '<div><br></div><div>Headline body</div><div><br></div><div>Paragraph two</div>';
        $cleaned1 = Article::cleanArticleBodyText($dirty1);
        $this->assertEquals("Headline body\n\nParagraph two", $cleaned1);
        $this->assertStringNotContainsString('<div>', $cleaned1);
        $this->assertStringNotContainsString('<br>', $cleaned1);
        $this->assertStringNotContainsString('</div>', $cleaned1);

        $dirty2 = '<p>First paragraph.</p><p><br></p><p>Second paragraph.</p>';
        $cleaned2 = Article::cleanArticleBodyText($dirty2);
        $this->assertEquals("First paragraph.\n\nSecond paragraph.", $cleaned2);
        $this->assertStringNotContainsString('<p>', $cleaned2);
        $this->assertStringNotContainsString('</p>', $cleaned2);

        $dirty3 = '<div>Line 1 with &lt;div&gt;&lt;br&gt;&lt;/div&gt; entity tags</div>';
        $cleaned3 = Article::cleanArticleBodyText($dirty3);
        $this->assertStringNotContainsString('&lt;div&gt;', $cleaned3);
        $this->assertStringNotContainsString('<div>', $cleaned3);
        $this->assertStringNotContainsString('<br>', $cleaned3);
    }

    public function test_article_model_mutator_automatically_cleans_content_and_excerpt(): void
    {
        $role = Role::firstOrCreate(['role_name' => 'Writer']);
        $user = User::create([
            'role_id' => $role->id,
            'student_number' => '2023-99999-BN-0',
            'full_name' => 'Test Mutator Writer',
            'email' => 'mutator@test.com',
            'password' => bcrypt('password123'),
        ]);

        $category = Category::firstOrCreate(['name' => 'News', 'slug' => 'news']);

        $article = Article::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => 'Test Article Clean Text',
            'excerpt' => '<div>Excerpt line 1</div><div><br></div><div>Excerpt line 2</div>',
            'content' => '<div>Content para 1</div><div><br></div><div>Content para 2</div>',
            'status' => 'Draft',
        ]);

        $this->assertEquals("Content para 1\n\nContent para 2", $article->content);
        $this->assertEquals("Excerpt line 1\n\nExcerpt line 2", $article->excerpt);
        $this->assertStringNotContainsString('<div>', $article->content);
        $this->assertStringNotContainsString('<br>', $article->content);
        $this->assertStringNotContainsString('<div>', $article->excerpt);
        $this->assertStringNotContainsString('<br>', $article->excerpt);
    }
}
