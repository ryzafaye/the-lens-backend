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
     * Sanitizes article body content to store pure text only without <div>, <br>, or other HTML wrapper tags.
     */
    public static function cleanArticleBodyText(?string $content): string
    {
        if ($content === null || trim($content) === '') {
            return '';
        }

        $text = $content;

        // Decode HTML entities (up to 3 passes for nested encoding)
        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) break;
            $text = $decoded;
        }

        // Replace non-breaking spaces
        $text = str_replace(["&nbsp;", "\u{00A0}"], ' ', $text);

        // Replace empty block lines like <div><br></div> or <p><br></p> with newlines
        $text = preg_replace('/<(?:div|p)[^>]*>\s*<br\s*\/?>\s*<\/(?:div|p)>/i', "\n", $text);

        // Replace <br> tags with newlines
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);

        // Replace block boundaries with newlines
        $text = preg_replace('/<\/(?:div|p|h[1-6]|li|blockquote)>\s*<(?:div|p|h[1-6]|li|blockquote)[^>]*>/i', "\n\n", $text);
        $text = preg_replace('/<\/(?:div|p|h[1-6]|li|blockquote)>/i', "\n", $text);
        $text = preg_replace('/<(?:div|p|h[1-6]|li|blockquote)[^>]*>/i', '', $text);

        // Strip all remaining HTML tags
        $text = strip_tags($text);

        // In case literal text <div> or <br> remained (e.g. escaped in input)
        $text = preg_replace('/<\/?(?:div|p|br)[^>]*>/i', '', $text);

        // Normalize carriage returns and trim lines
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $trimmedLines = array_map('trim', $lines);
        $text = implode("\n", $trimmedLines);

        // Collapse 3 or more newlines into double newline (clean paragraph separation)
        $text = preg_replace('/(\n\s*){3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Mutator to ensure article body content is always saved as clean pure text in the database.
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
