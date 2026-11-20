<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Article;

class CleanArticleContent extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'articles:clean-content';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean <div><br> and HTML wrapper tags from article content and excerpt, keeping text article content only.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting article content cleaning...');

        $count = 0;
        DB::table('articles')->orderBy('id')->chunk(100, function ($articles) use (&$count) {
            foreach ($articles as $article) {
                $hasContentTags = $article->content && (
                    str_contains($article->content, '<') ||
                    str_contains($article->content, '&lt;') ||
                    str_contains($article->content, '&amp;') ||
                    str_contains($article->content, '<div>') ||
                    str_contains($article->content, '<br>')
                );

                $hasExcerptTags = $article->excerpt && (
                    str_contains($article->excerpt, '<') ||
                    str_contains($article->excerpt, '&lt;') ||
                    str_contains($article->excerpt, '&amp;') ||
                    str_contains($article->excerpt, '<div>') ||
                    str_contains($article->excerpt, '<br>')
                );

                if ($hasContentTags || $hasExcerptTags) {
                    $updates = [];
                    if ($hasContentTags) {
                        $updates['content'] = Article::cleanArticleBodyText($article->content);
                    }
                    if ($hasExcerptTags) {
                        $updates['excerpt'] = Article::cleanArticleBodyText($article->excerpt);
                    }
                    if (!empty($updates)) {
                        DB::table('articles')->where('id', $article->id)->update($updates);
                        $count++;
                    }
                }
            }
        });

        Article::clearArticleCaches();

        $this->info("Successfully cleaned {$count} articles. All article bodies are now pure text only.");
        return 0;
    }
}
