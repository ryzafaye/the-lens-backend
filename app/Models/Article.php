<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;

class Article extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'slug',
        'category_id',
        'excerpt',
        'content',
        'status',
        'is_archived',
        'views',
        'shares',
        'remarks',
        'published_at'
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'is_archived' => 'boolean',
    ];

    /**
     * Request-level static cache to eliminate redundant database queries in getEndorserAttribute().
     */
    protected static array $endorserCache = [];

    /**
     * Checks if the incoming article body content is meaningfully modified
     * compared to the existing/original content (ignoring raw tags/whitespace).
     */
    public static function isBodyModified(?string $originalBody, ?string $newBody): bool
    {
        $cleanOrig = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string) $originalBody, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        $cleanNew = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string) $newBody, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        return $cleanOrig !== $cleanNew;
    }

    /**
     * Sanitizes article body content to store clean HTML with safe formatting,
     * preserving indentations and alignments (center, right, justify, left),
     * lists (<ul>, <ol>, <li>), headings (<h2>, <h3>, <h4>), blockquotes,
     * and safe inline styling without leaking raw code tags like <div>, <br>, <code>, <pre>,
     * or empty wrapper lines in the database.
     */
    public static function cleanArticleBodyText(?string $content): string
    {
        if ($content === null || trim($content) === '') {
            return '';
        }

        $text = $content;

        // 1. Strip dangerous tags, event handlers, and javascript: links
        $text = preg_replace('/<(script|style|iframe|object|embed)\b[^<]*(?:(?!<\/\1>)<[^<]*)*<\/\1>/i', '', $text);
        $text = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $text);
        $text = preg_replace('/href\s*=\s*["\']\s*javascript:[^"\']*["\']/i', 'href="#"', $text);

        // 2. Decode HTML entities (up to 3 passes if double-escaped tags like &lt;p&gt; exist)
        for ($i = 0; $i < 3; $i++) {
            if (!preg_match('/&amp;(?:lt|gt|quot|#0?39|amp);|&(?:lt|gt);/i', $text)) {
                break;
            }
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) break;
            $text = $decoded;
        }

        // Replace non-breaking spaces
        $text = str_replace(["&nbsp;", "\u{00A0}"], ' ', $text);

        // 3. Remove all empty <div><br></div>, <p><br></p>, and raw <br> empty lines completely
        $text = preg_replace('/<(?:div|p)[^>]*>\s*(?:<br\s*\/?>)?\s*<\/(?:div|p)>/i', '', $text);
        $text = preg_replace('/<div[^>]*><br\s*\/?>/i', '', $text);

        // 4. Normalize align="center|right|justify|left" attributes to style="text-align: ..."
        $text = preg_replace_callback('/\\balign\\s*=\\s*["\']?(center|right|justify|left)["\']?/i', function ($m) {
            return 'style="text-align: ' . strtolower($m[1]) . ';"';
        }, $text);

        // 5. Sanitize style attributes to keep ONLY text-align and margin-left / padding-left
        $text = preg_replace_callback('/\\bstyle\\s*=\\s*["\']([^"\']*)["\']/i', function ($m) {
            $rawStyle = $m[1];
            $kept = [];
            if (preg_match('/text-align\\s*:\\s*(center|right|justify|left)/i', $rawStyle, $match)) {
                $kept[] = 'text-align: ' . strtolower($match[1]) . ';';
            }
            if (preg_match('/(?:margin-left|padding-left)\\s*:\\s*([0-9.]+(?:px|em|rem|%))/i', $rawStyle, $match)) {
                $kept[] = 'margin-left: ' . strtolower($match[1]) . ';';
            }
            return !empty($kept) ? 'style="' . implode(' ', $kept) . '"' : '';
        }, $text);

        // 6. Convert any <div> wrapper to semantic <p>
        $text = preg_replace_callback('/<div(\s+[^>]*)?>(.*?)<\/div>/is', function ($m) {
            $attrs = trim($m[1] ?? '');
            $inner = trim($m[2] ?? '');
            if ($inner === '' || preg_match('/^(?:<br\s*\/?>|\s*)+$/i', $inner)) {
                return '';
            }
            return '<p' . ($attrs ? ' ' . $attrs : '') . '>' . $inner . '</p>';
        }, $text);

        // 7. Strip stray/residual <div>, <br>, <code>, <pre> tags and any code tags completely
        $text = preg_replace('/<\/?(?:div|br|code|pre)\b[^>]*>/i', '', $text);
        $text = preg_replace('/&lt;\/?(?:div|br|code|pre)\b[^&gt;]*&gt;/i', '', $text);

        // 8. Strip all tags EXCEPT semantic rich text formatting tags (NO <div>, NO <br>, NO <code>, NO <pre>)
        $allowedTags = '<p><ul><ol><li><h2><h3><h4><blockquote><strong><b><em><i><u><s><strike><del><sub><sup><a><hr>';
        $text = strip_tags($text, $allowedTags);

        // 9. Remove any empty tags that might have resulted
        $text = preg_replace('/<(?:p|h[1-6]|blockquote)[^>]*>\s*<\/(?:p|h[1-6]|blockquote)>/i', '', $text);

        // 10. Clean empty paragraphs at edges
        $text = preg_replace('/^(?:\s*<p[^>]*>\s*<\/p>)+/i', '', $text);
        $text = preg_replace('/(?:\s*<p[^>]*>\s*<\/p>)+$/i', '', $text);

        // Normalize carriage returns
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return trim($text);
    }

    /**
     * Mutator to ensure article body content is always saved with sanitized, safe rich content in the database.
     */
    public function setContentAttribute($value): void
    {
        $this->attributes['content'] = static::cleanArticleBodyText(is_string($value) ? $value : (string)$value);
    }

    /**
     * Mutator to ensure article excerpt is clean text in the database.
     */
    public function setExcerptAttribute($value): void
    {
        $this->attributes['excerpt'] = static::cleanArticleBodyText(is_string($value) ? $value : (string)$value);
    }

    protected static function booted(): void
    {
        static::saving(function (Article $article) {
            if (empty($article->slug) && !empty($article->title)) {
                $baseSlug = \Illuminate\Support\Str::slug($article->title) ?: 'article';
                $slug = $baseSlug;
                $count = 1;
                while (static::where('slug', $slug)->where('id', '!=', $article->id)->exists()) {
                    $slug = "{$baseSlug}-{$count}";
                    $count++;
                }
                $article->slug = $slug;
            }
        });

        static::saved(function (Article $article) {
            static::clearArticleCaches($article);
        });

        static::deleted(function (Article $article) {
            static::clearArticleCaches($article);
        });
    }

    /**
     * Automatically invalidates relevant cache keys whenever articles are created, updated, or deleted.
     */
    public static function clearArticleCaches(?Article $article = null): void
    {
        try {
            Cache::forget('public_home_data_web');
            Cache::forget('public_home_data_api');
            Cache::forget('public_categories_all');
            Cache::forget('all_categories');
            Cache::forget('all_categories_map_id_name');
            Cache::forget('all_articles_for_management');
            Cache::forget('eic_articles_for_management');
            Cache::forget('eic_dashboard_stats');
            Cache::forget('eic_dashboard_pending_articles');
            Cache::forget('eic_dashboard_published_articles');
            Cache::forget('eic_review_articles_newest');
            Cache::forget('eic_review_articles_oldest');
            Cache::forget('exec_dashboard_pending_pres_vp');
            Cache::forget('exec_dashboard_pending_all');
            Cache::forget('exec_review_articles_all_newest');
            Cache::forget('exec_review_articles_all_oldest');
            Cache::forget('mpc_dashboard_stats');
            Cache::forget('mpc_dashboard_pending_articles');
            Cache::forget('mpc_dashboard_published_articles');
            Cache::forget('section_head_dashboard_recent_activities');

            $headUsers = \App\Models\User::whereHas('role', function ($q) {
                $q->where('role_name', 'like', '%Editor%')
                  ->orWhere('role_name', 'like', '%Head%')
                  ->orWhere('role_name', 'like', '%Section%')
                  ->orWhere('role_name', 'like', '%President%')
                  ->orWhere('role_name', 'like', '%VP%');
            })->pluck('id');

            foreach ($headUsers as $hid) {
                Cache::forget("section_head_review_cat_counts_{$hid}");
                Cache::forget("section_head_dashboard_stats_{$hid}");
                Cache::forget("section_head_dashboard_stats_{$hid}_all");
                Cache::forget("section_head_dashboard_pending_{$hid}");
                Cache::forget("section_head_dashboard_pending_{$hid}_all");
                Cache::forget("section_head_recent_articles_{$hid}");
                Cache::forget("section_head_review_articles_{$hid}_all_newest");
                Cache::forget("section_head_review_articles_{$hid}_all_oldest");
                Cache::forget("section_head_revision_articles_{$hid}_all_newest");
                Cache::forget("section_head_revision_articles_{$hid}_all_oldest");
                if ($article && $article->category_id) {
                    Cache::forget("section_head_review_articles_{$hid}_{$article->category_id}_newest");
                    Cache::forget("section_head_review_articles_{$hid}_{$article->category_id}_oldest");
                    Cache::forget("section_head_revision_articles_{$hid}_{$article->category_id}_newest");
                    Cache::forget("section_head_revision_articles_{$hid}_{$article->category_id}_oldest");
                    Cache::forget("section_head_dashboard_stats_{$hid}_{$article->category_id}");
                    Cache::forget("section_head_dashboard_pending_{$hid}_{$article->category_id}");
                }
            }

            if ($article) {
                if ($article->slug) {
                    Cache::forget("public_article_{$article->slug}");
                }
                if ($article->id) {
                    Cache::forget("public_article_{$article->id}");
                }
                if ($article->user_id) {
                    Cache::forget("writer_manage_articles_{$article->user_id}");
                    Cache::forget("writer_recent_articles_{$article->user_id}");
                    Cache::forget("writer_top_articles_{$article->user_id}");
                    Cache::forget("writer_dashboard_stats_{$article->user_id}");
                    Cache::forget("writer_drafts_{$article->user_id}");
                    Cache::forget("section_head_manage_articles_{$article->user_id}");
                }
                if ($article->category_id) {
                    Cache::forget("public_category_articles_{$article->category_id}");
                    Cache::forget("exec_dashboard_pending_{$article->category_id}");
                    Cache::forget("exec_review_articles_{$article->category_id}_newest");
                    Cache::forget("exec_review_articles_{$article->category_id}_oldest");
                    Cache::forget("section_head_dashboard_published_{$article->category_id}");
                    Cache::forget("section_head_dashboard_journalists_{$article->category_id}");
                }
            }
        } catch (\Throwable $e) {}
    }

    /**
     * Formats Cloudinary URLs with auto-format, auto-quality, and thumbnail sizing.
     */
    public static function optimizeCloudinaryUrl(?string $url, string $transformation = 'w_800,c_limit,f_auto,q_auto'): ?string
    {
        if (!$url || !str_contains($url, 'res.cloudinary.com') || !str_contains($url, '/upload/')) {
            return $url;
        }
        if (str_contains($url, 'f_auto') || str_contains($url, 'q_auto')) {
            return $url;
        }
        return str_replace('/upload/', '/upload/' . $transformation . '/', $url);
    }

    protected $appends = ['date', 'endorser', 'cover_url', 'cover_artist'];

    public function getCoverUrlAttribute(): ?string
    {
        $url = $this->coverImage?->file_url;
        if (!$url) {
            return null;
        }
        return static::optimizeCloudinaryUrl($url, 'w_800,c_limit,f_auto,q_auto');
    }

    public function getCoverArtistAttribute(): ?string
    {
        return $this->coverImage?->artist_name;
    }

    public function getDateAttribute()
    {
        return $this->created_at ? $this->created_at->format('M d, Y') : '';
    }

    public function getEndorserAttribute()
    {
        $categoryName = $this->category->name ?? '';
        if (empty($categoryName) && $this->category_id) {
            if ($this->relationLoaded('category')) {
                $categoryName = $this->category?->name ?? '';
            } else {
                $cachedCategories = Cache::remember('all_categories', 86400, fn() => Category::all());
                $categoryName = $cachedCategories->firstWhere('id', $this->category_id)?->name ?? '';
            }
        }

        // Strictly determine Section Head based on the submitted article's category
        try {
            $headRole = null;
            if (stripos($categoryName, 'News') !== false) {
                $headRole = 'News Head';
            } elseif (stripos($categoryName, 'Liter') !== false) {
                $headRole = 'Literary Head';
            } elseif (stripos($categoryName, 'Feature') !== false) {
                $headRole = 'Feature Head';
            } elseif (stripos($categoryName, 'Sci') !== false || stripos($categoryName, 'Tech') !== false) {
                $headRole = 'Science and Technology Head';
            } elseif (stripos($categoryName, 'Opinion') !== false) {
                $headRole = 'Associate Editor-in-Chief';
            }

            if ($headRole) {
                if (array_key_exists($headRole, static::$endorserCache)) {
                    return static::$endorserCache[$headRole];
                }

                $headUserQuery = User::whereHas('role', fn($q) => $q->where('role_name', $headRole));
                if (stripos($categoryName, 'Opinion') !== false) {
                    $headUserQuery->where('full_name', 'not like', '%Fax%');
                }
                $headUser = $headUserQuery->first();
                if ($headUser) {
                    $parts = preg_split('/\s+/', trim($headUser->full_name));
                    $last = array_pop($parts);
                    $prev = end($parts);
                    $lastName = ($prev && in_array(strtolower($prev), ['de', 'dela', 'del', 'delos', 'san'])) ? ($prev . ' ' . $last) : $last;
                    $headLabel = (stripos($headRole, 'Science') !== false) ? '(SciTech Head)' : ((stripos($headRole, 'Opinion') !== false || stripos($headRole, 'Editor') !== false) ? '(Opinion Head)' : "({$headRole})");
                    $val = "{$lastName} {$headLabel}";
                    static::$endorserCache[$headRole] = $val;
                    return $val;
                }
            }
        } catch (\Throwable $e) {
            // DB fallback
        }

        // Category Head Title Fallback
        $fallbackMap = [
            'news' => 'News Head',
            'literature' => 'Literary Head',
            'literary' => 'Literary Head',
            'feature' => 'Feature Head',
            'features' => 'Feature Head',
            'science' => 'SciTech Head',
            'scitech' => 'SciTech Head',
            'opinion' => 'Opinion Head',
            'opinions' => 'Opinion Head',
        ];

        $lowerCat = strtolower($categoryName);
        foreach ($fallbackMap as $key => $val) {
            if (str_contains($lowerCat, $key)) {
                return $val;
            }
        }

        // Fallback: check remarks if category could not be resolved
        if (!empty($this->remarks)) {
            if (preg_match('/(?:Endorsed to EIC by|Approved by(?: Section Head)?:?)\s*([^\r\n]+)/i', $this->remarks, $matches)) {
                $matched = trim($matches[1]);
                if (!empty($matched)) {
                    return $matched;
                }
            }
        }

        return 'Section Head';
    }

    public function getEndorsedByAttribute()
    {
        return $this->endorser;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ArticleImage::class);
    }

    public function coverImage(): HasOne
    {
        return $this->hasOne(ArticleImage::class)->where('is_cover', true);
    }
}
