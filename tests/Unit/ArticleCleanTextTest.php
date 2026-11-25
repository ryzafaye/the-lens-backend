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
        $dirty1 = '<div><br></div><div>Headline body</div><div><br></div><div>Paragraph two</div><br>';
        $cleaned1 = Article::cleanArticleBodyText($dirty1);
        $this->assertStringContainsString('Headline body', $cleaned1);
        $this->assertStringContainsString('Paragraph two', $cleaned1);
        $this->assertStringNotContainsString('<div', $cleaned1);
        $this->assertStringNotContainsString('<br', $cleaned1);

        $dirty2 = '<p>First paragraph.</p><p><br></p><p>Second paragraph.</p>';
        $cleaned2 = Article::cleanArticleBodyText($dirty2);
        $this->assertStringContainsString('<p>First paragraph.</p>', $cleaned2);
        $this->assertStringContainsString('<p>Second paragraph.</p>', $cleaned2);
        $this->assertStringNotContainsString('<br', $cleaned2);

        $dirty3 = '<div>Line 1 with &lt;div&gt;&lt;br&gt;&lt;/div&gt; entity tags</div>';
        $cleaned3 = Article::cleanArticleBodyText($dirty3);
        $this->assertStringNotContainsString('&lt;div&gt;', $cleaned3);
        $this->assertStringNotContainsString('&lt;br&gt;', $cleaned3);
        $this->assertStringNotContainsString('<div', $cleaned3);
        $this->assertStringNotContainsString('<br', $cleaned3);

        // Verify rich text elements and that <ul>, <ol>, and <li> tags are preserved cleanly in DB
        $richContent = "<h2>Title Heading</h2>\n<p>Introduction paragraph.</p>\n<ul><li>First bullet</li><li>Second bullet</li></ul>\n<ol><li>Numbered step 1</li><li>Numbered step 2</li></ol>\n<blockquote><strong>Important note</strong> in quote</blockquote>";
        $cleanedRich = Article::cleanArticleBodyText($richContent);
        $this->assertStringContainsString('<h2>Title Heading</h2>', $cleanedRich);
        $this->assertStringContainsString('<ul><li>First bullet</li><li>Second bullet</li></ul>', $cleanedRich);
        $this->assertStringContainsString('<ol><li>Numbered step 1</li><li>Numbered step 2</li></ol>', $cleanedRich);
        $this->assertStringContainsString('<blockquote><strong>Important note</strong> in quote</blockquote>', $cleanedRich);

        // Verify that dangerous scripts and event handlers are removed
        $dangerous = '<p onclick="alert(1)">Click me</p><script>alert("xss")</script><iframe src="evil.com"></iframe>';
        $cleanedDangerous = Article::cleanArticleBodyText($dangerous);
        $this->assertStringNotContainsString('onclick', $cleanedDangerous);
        $this->assertStringNotContainsString('<script', $cleanedDangerous);
        $this->assertStringNotContainsString('<iframe', $cleanedDangerous);
        $this->assertStringContainsString('<p>Click me</p>', $cleanedDangerous);

        // Verify that indentations and alignments (right, left, center, justified) are preserved
        $aligned = '<p style="text-align: center;">Centered title</p><div style="text-align: right;">Right date</div><p style="text-align: justify;">Justified paragraph.</p><div align="center">Legacy align center</div><blockquote style="margin-left: 40px;">Indented quote</blockquote>';
        $cleanedAligned = Article::cleanArticleBodyText($aligned);
        $this->assertStringContainsString('style="text-align: center;"', $cleanedAligned);
        $this->assertStringContainsString('style="text-align: right;"', $cleanedAligned);
        $this->assertStringContainsString('style="text-align: justify;"', $cleanedAligned);
        $this->assertStringContainsString('style="margin-left: 40px;"', $cleanedAligned);
        $this->assertStringContainsString('<blockquote', $cleanedAligned);
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
            'excerpt' => '<div>Excerpt line 1</div><div>Excerpt line 2</div>',
            'content' => '<p>Content para 1</p><ul><li>Item A</li><li>Item B</li></ul>',
            'status' => 'Draft',
        ]);

        $this->assertStringContainsString('Content para 1', $article->content);
        $this->assertStringContainsString('<ul><li>Item A</li><li>Item B</li></ul>', $article->content);
        $this->assertStringContainsString('Excerpt line 1', $article->excerpt);
        $this->assertStringContainsString('Excerpt line 2', $article->excerpt);
    }
}
