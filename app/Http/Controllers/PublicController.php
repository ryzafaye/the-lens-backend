<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Category;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class PublicController extends Controller
{
    /**
     * Standard columns to select for list views, excluding heavy body/content fields.
     */
    protected const LIST_COLUMNS = [
        'id',
        'title',
        'slug',
        'excerpt',
        'category_id',
        'user_id',
        'published_at',
        'views',
        'shares',
        'created_at',
    ];

    /**
     * Display the public homepage.
     */
    public function index(): View
    {
        $categories = Cache::remember('public_categories_all', 600, function () {
            return Category::select(['id', 'name', 'slug'])->get();
        });

        $cachedData = Cache::remember('public_home_data_web', 600, function () use ($categories) {
            $baseQuery = Article::with([
                'coverImage:id,article_id,file_url,is_cover',
                'user:id,full_name,role_id',
                'category:id,name,slug'
            ])
            ->select(self::LIST_COLUMNS)
            ->whereRaw('LOWER(status) = ?', ['published'])
            ->where('is_archived', false)
            ->latest('published_at');

            $featuredArticle = (clone $baseQuery)->first();

            $latestArticles = (clone $baseQuery)
                ->when($featuredArticle, fn ($q) => $q->where('id', '!=', $featuredArticle->id))
                ->take(4)
                ->get();

            $excludeIds = array_filter(array_merge(
                [$featuredArticle?->id],
                $latestArticles->pluck('id')->all()
            ));

            $moreStories = (clone $baseQuery)
                ->whereNotIn('id', $excludeIds)
                ->take(3)
                ->get();

            $categoryIds = $categories->pluck('id')->all();
            $categoryArticles = [];
            if (!empty($categoryIds)) {
                $allArticles = Article::with([
                    'coverImage:id,article_id,file_url,is_cover',
                    'user:id,full_name,role_id',
                    'category:id,name,slug'
                ])
                ->select(self::LIST_COLUMNS)
                ->whereIn('category_id', $categoryIds)
                ->whereRaw('LOWER(status) = ?', ['published'])
                ->where('is_archived', false)
                ->latest('published_at')
                ->get()
                ->groupBy('category_id');

                foreach ($categories as $cat) {
                    $categoryArticles[$cat->id] = ($allArticles->get($cat->id) ?? collect())->take(3)->values();
                }
            }

            return compact('featuredArticle', 'latestArticles', 'moreStories', 'categoryArticles');
        });

        return view('index', array_merge($cachedData, ['categories' => $categories]));
    }

    /**
     * JSON API endpoint for the public homepage.
     * Mirrors the data prepared in index() but returns a JSON payload.
     */
    public function apiHome(): JsonResponse
    {
        $categories = Cache::remember('public_categories_all', 600, function () {
            return Category::select(['id', 'name', 'slug'])->get();
        });

        $props = Cache::remember('public_home_data_api', 600, function () use ($categories) {
            $baseQuery = Article::with([
                'coverImage:id,article_id,file_url,is_cover',
                'user:id,full_name,role_id',
                'category:id,name,slug'
            ])
            ->select(self::LIST_COLUMNS)
            ->whereRaw('LOWER(status) = ?', ['published'])
            ->where('is_archived', false)
            ->latest('published_at');

            $featuredArticle = (clone $baseQuery)->first();

            $latestArticles = (clone $baseQuery)
                ->when($featuredArticle, fn ($q) => $q->where('id', '!=', $featuredArticle->id))
                ->take(4)
                ->get();

            $excludeIds = array_filter(array_merge(
                [$featuredArticle?->id],
                $latestArticles->pluck('id')->all()
            ));

            $moreStories = (clone $baseQuery)
                ->whereNotIn('id', $excludeIds)
                ->take(3)
                ->get();

            $categoryIds = $categories->pluck('id')->all();
            $categoryArticles = [];
            if (!empty($categoryIds)) {
                $allArticles = Article::with([
                    'coverImage:id,article_id,file_url,is_cover',
                    'user:id,full_name,role_id',
                    'category:id,name,slug'
                ])
                ->select(self::LIST_COLUMNS)
                ->whereIn('category_id', $categoryIds)
                ->whereRaw('LOWER(status) = ?', ['published'])
                ->where('is_archived', false)
                ->latest('published_at')
                ->get()
                ->groupBy('category_id');

                foreach ($categories as $cat) {
                    $categoryArticles[$cat->id] = ($allArticles->get($cat->id) ?? collect())->take(3)->values();
                }
            }

            $shapeArticle = function (Article $article) {
                $text = !empty($article->content) ? $article->content : ($article->excerpt ?? '');
                $words = str_word_count(strip_tags($text));
                $readMinutes = max(1, (int) ceil(($words > 100 ? $words : $words * 4) / 200));
                $imageUrl = $article->cover_url ?: asset('images/maroon.jpg');
                $categoryName = $article->category->name ?? null;
                $authorName = $article->user->full_name ?? null;
                $authorUrl = $authorName ? route('public.author', $article->user_id) : null;

                return [
                    'id' => $article->id,
                    'url' => route('public.article', $article->slug),
                    'imageUrl' => $imageUrl,
                    'title' => $article->title,
                    'category' => $categoryName,
                    'publishedAt' => $article->published_at ? $article->published_at->format('M d, Y') : $article->created_at->format('M d, Y'),
                    'excerpt' => $article->excerpt,
                    'author' => $authorName,
                    'authorUrl' => $authorUrl,
                    'views' => $article->views,
                    'shares' => $article->shares,
                    'readMinutes' => $readMinutes,
                ];
            };

            return [
                'featuredArticle' => $featuredArticle ? $shapeArticle($featuredArticle) : null,
                'latestArticles' => $latestArticles->map($shapeArticle)->values(),
                'moreStories' => $moreStories->map($shapeArticle)->values(),
                'categorySections' => $categories->map(function ($cat) use ($categoryArticles, $shapeArticle) {
                    $articles = $categoryArticles[$cat->id] ?? collect();
                    return (object) [
                        'id' => $cat->id,
                        'name' => $cat->name,
                        'slug' => $cat->slug,
                        'articles' => $articles->map($shapeArticle)->values(),
                    ];
                })->values(),
            ];
        });

        return response()->json([
            'props' => $props,
            'shared' => new \stdClass(),
        ]);
    }

    /**
     * JSON API endpoint for article archives with filters.
     * Mirrors the query logic prepared in archives() but returns a JSON payload.
     */
    public function apiArchives(Request $request): JsonResponse
    {
        $categoryParam = (string) $request->query('category', 'All');
        $queryParam = trim((string) $request->query('q', ''));
        $sortBy = (string) $request->query('sort', 'newest');
        $dateType = (string) $request->query('dateType', 'any');
        $dateValue = (string) $request->query('dateValue', '');

        $articlesQuery = Article::with([
            'coverImage:id,article_id,file_url,is_cover',
            'user:id,full_name,role_id',
            'category:id,name,slug'
        ])
        ->select(self::LIST_COLUMNS)
        ->whereRaw('LOWER(status) = ?', ['published'])
        ->where('is_archived', false);

        if ($categoryParam !== 'All' && !empty($categoryParam)) {
            $articlesQuery->whereHas('category', function ($q) use ($categoryParam) {
                $q->where('name', $categoryParam)
                  ->orWhere('slug', $categoryParam);
            });
        }

        if ($queryParam !== '') {
            $lower = strtolower($queryParam);
            $articlesQuery->where(function ($q) use ($lower) {
                $q->whereRaw('LOWER(title) LIKE ?', ["%{$lower}%"])
                  ->orWhereRaw('LOWER(excerpt) LIKE ?', ["%{$lower}%"])
                  ->orWhereRaw('LOWER(content) LIKE ?', ["%{$lower}%"])
                  ->orWhereHas('user', fn ($u) => $u->whereRaw('LOWER(full_name) LIKE ?', ["%{$lower}%"]));
            });
        }

        if ($dateType === 'today') {
            $articlesQuery->whereDate('published_at', now()->toDateString());
        } elseif ($dateType === 'this_week') {
            $articlesQuery->whereBetween('published_at', [now()->startOfWeek(), now()->endOfWeek()]);
        } elseif ($dateType === 'choose' && !empty($dateValue)) {
            $articlesQuery->whereDate('published_at', $dateValue);
        }

        match ($sortBy) {
            'oldest' => $articlesQuery->oldest('published_at'),
            'popular' => $articlesQuery->orderByDesc('views'),
            'az' => $articlesQuery->orderBy('title', 'asc'),
            default => $articlesQuery->latest('published_at'),
        };

        $articles = $articlesQuery->paginate(12)->withQueryString();
        $categories = Cache::remember('public_categories_all', 600, function () {
            return Category::select(['id', 'name', 'slug'])->get();
        });

        $shapeArticle = function (Article $article) {
            $text = !empty($article->content) ? $article->content : ($article->excerpt ?? '');
            $words = str_word_count(strip_tags($text));
            $readMinutes = max(1, (int) ceil(($words > 100 ? $words : $words * 4) / 200));
            $imageUrl = $article->cover_url ?: asset('images/maroon.jpg');
            $categoryName = $article->category->name ?? null;
            $authorName = $article->user->full_name ?? null;
            $authorUrl = $authorName ? route('public.author', $article->user_id) : null;

            return [
                'id' => $article->id,
                'url' => route('public.article', $article->slug),
                'imageUrl' => $imageUrl,
                'title' => $article->title,
                'category' => $categoryName,
                'publishedAt' => $article->published_at ? $article->published_at->format('M d, Y') : $article->created_at->format('M d, Y'),
                'excerpt' => $article->excerpt,
                'author' => $authorName,
                'authorUrl' => $authorUrl,
                'views' => $article->views,
                'shares' => $article->shares,
                'readMinutes' => $readMinutes,
            ];
        };

        $pagination = $articles->linkCollection()->map(function ($link) {
            $url = null;
            if ($link['url']) {
                $parsed = parse_url($link['url']);
                $url = '/archives' . (!empty($parsed['query']) ? '?' . $parsed['query'] : '');
            }
            return [
                'url' => $url,
                'label' => $link['label'],
                'active' => $link['active'],
            ];
        })->values();

        $props = [
            'articles' => $articles->map($shapeArticle)->values(),
            'pagination' => $pagination,
            'categories' => $categories,
            'filters' => [
                'query' => $queryParam,
                'category' => $categoryParam,
                'sort' => $sortBy,
                'dateType' => $dateType,
                'dateValue' => $dateValue,
            ],
            'total' => $articles->total(),
        ];

        return response()->json([
            'props' => $props,
            'shared' => new \stdClass(),
        ]);
    }

    /**
     * Display articles within a specific category or latest releases.
     */
    public function category(string $category_slug): View
    {
        $slug = strtolower(trim($category_slug));

        if ($slug === 'latest') {
            $articles = Article::with([
                'coverImage:id,article_id,file_url,is_cover',
                'user:id,full_name,role_id',
                'category:id,name,slug'
            ])
            ->select(self::LIST_COLUMNS)
            ->whereRaw('LOWER(status) = ?', ['published'])
            ->where('is_archived', false)
            ->latest('published_at')
            ->paginate(12);

            $currentCategory = (object) [
                'id' => null,
                'name' => 'Latest Releases',
                'slug' => 'latest',
                'description' => 'Browse the latest news, features, opinions, and campus reports from The Lens.',
            ];

            return view('category', compact('articles', 'currentCategory'));
        }

        // Normalize slug variations
        $normalizedSlug = match ($slug) {
            'features' => 'feature',
            'science', 'science-and-technology', 'sci-tech' => 'science-technology',
            'opinions' => 'opinion',
            default => $slug,
        };

        $categoryQuery = Category::where('slug', $normalizedSlug);
        if (is_numeric($slug)) {
            $categoryQuery->orWhere('id', (int) $slug);
        }
        $category = $categoryQuery->first();

        if (!$category) {
            // Fuzzy search by category name if slug differs
            $category = Category::whereRaw('LOWER(name) LIKE ?', ["%{$normalizedSlug}%"])->firstOrFail();
        }

        $articles = Article::with([
            'coverImage:id,article_id,file_url,is_cover',
            'user:id,full_name,role_id',
            'category:id,name,slug'
        ])
        ->select(self::LIST_COLUMNS)
        ->where('category_id', $category->id)
        ->whereRaw('LOWER(status) = ?', ['published'])
        ->where('is_archived', false)
        ->latest('published_at')
        ->paginate(12);

        $currentCategory = (object) [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => "Stories and updates in {$category->name} from The Lens Publication.",
        ];

        return view('category', compact('articles', 'currentCategory'));
    }

    /**
     * Display a single published article.
     */
    public function article(string $article_slug): View
    {
        $article = Article::with(['coverImage', 'user.role', 'category'])
            ->where(function ($q) use ($article_slug) {
                $q->where('slug', $article_slug);
                if (is_numeric($article_slug)) {
                    $q->orWhere('id', (int) $article_slug);
                }
            })
            ->whereRaw('LOWER(status) = ?', ['published'])
            ->where('is_archived', false)
            ->firstOrFail();

        // Increment view count for the public article
        $article->increment('views');

        // Fetch related articles (same category, excluding current) with list columns
        $relatedArticles = Article::with([
            'coverImage:id,article_id,file_url,is_cover',
            'user:id,full_name,role_id',
            'category:id,name,slug'
        ])
        ->select(self::LIST_COLUMNS)
        ->where('id', '!=', $article->id)
        ->where('category_id', $article->category_id)
        ->whereRaw('LOWER(status) = ?', ['published'])
        ->where('is_archived', false)
        ->latest('published_at')
        ->take(3)
        ->get();

        if ($relatedArticles->count() < 3) {
            $excludeIds = array_merge([$article->id], $relatedArticles->pluck('id')->all());
            $backfill = Article::with([
                'coverImage:id,article_id,file_url,is_cover',
                'user:id,full_name,role_id',
                'category:id,name,slug'
            ])
            ->select(self::LIST_COLUMNS)
            ->whereNotIn('id', $excludeIds)
            ->whereRaw('LOWER(status) = ?', ['published'])
            ->where('is_archived', false)
            ->latest('published_at')
            ->take(3 - $relatedArticles->count())
            ->get();
            $relatedArticles = $relatedArticles->concat($backfill);
        }

        return view('article', compact('article', 'relatedArticles'));
    }

    /**
     * Record a share / copy link event for an article.
     */
    public function share(int $id): JsonResponse
    {
        $article = Article::findOrFail($id);
        $article->increment('shares');

        return response()->json([
            'success' => true,
            'shares' => $article->shares,
        ]);
    }

    /**
     * Display article archives with filters.
     */
    public function archives(Request $request): View
    {
        $categoryParam = (string) $request->query('category', 'All');
        $queryParam = trim((string) $request->query('q', ''));
        $sortBy = (string) $request->query('sort', 'newest');
        $dateType = (string) $request->query('dateType', 'any');
        $dateValue = (string) $request->query('dateValue', '');

        $articlesQuery = Article::with([
            'coverImage:id,article_id,file_url,is_cover',
            'user:id,full_name,role_id',
            'category:id,name,slug'
        ])
        ->select(self::LIST_COLUMNS)
        ->whereRaw('LOWER(status) = ?', ['published'])
        ->where('is_archived', false);

        if ($categoryParam !== 'All' && !empty($categoryParam)) {
            $articlesQuery->whereHas('category', function ($q) use ($categoryParam) {
                $q->where('name', $categoryParam)
                  ->orWhere('slug', $categoryParam);
            });
        }

        if ($queryParam !== '') {
            $lower = strtolower($queryParam);
            $articlesQuery->where(function ($q) use ($lower) {
                $q->whereRaw('LOWER(title) LIKE ?', ["%{$lower}%"])
                  ->orWhereRaw('LOWER(excerpt) LIKE ?', ["%{$lower}%"])
                  ->orWhereRaw('LOWER(content) LIKE ?', ["%{$lower}%"])
                  ->orWhereHas('user', fn ($u) => $u->whereRaw('LOWER(full_name) LIKE ?', ["%{$lower}%"]));
            });
        }

        if ($dateType === 'today') {
            $articlesQuery->whereDate('published_at', now()->toDateString());
        } elseif ($dateType === 'this_week') {
            $articlesQuery->whereBetween('published_at', [now()->startOfWeek(), now()->endOfWeek()]);
        } elseif ($dateType === 'choose' && !empty($dateValue)) {
            $articlesQuery->whereDate('published_at', $dateValue);
        }

        match ($sortBy) {
            'oldest' => $articlesQuery->oldest('published_at'),
            'popular' => $articlesQuery->orderByDesc('views'),
            'az' => $articlesQuery->orderBy('title', 'asc'),
            default => $articlesQuery->latest('published_at'),
        };

        $articles = $articlesQuery->paginate(12)->withQueryString();
        $categories = Cache::remember('public_categories_all', 600, function () {
            return Category::select(['id', 'name', 'slug'])->get();
        });

        return view('archives', compact(
            'articles',
            'categories',
            'categoryParam',
            'queryParam',
            'sortBy',
            'dateType',
            'dateValue'
        ));
    }

    /**
     * Display author profile page.
     */
    public function author(string $author_id): View
    {
        $user = null;
        if (is_numeric($author_id)) {
            $user = User::with('role')->find($author_id);
        } else {
            $decoded = urldecode($author_id);
            $user = User::with('role')->whereRaw('LOWER(full_name) = ?', [strtolower($decoded)])->first();
        }

        if (!$user) {
            $authorArticles = collect();
            return view('author', [
                'author' => null,
                'authorName' => urldecode($author_id),
                'articles' => $authorArticles,
                'roleName' => 'Contributing Writer',
                'totalViews' => 0,
                'beats' => collect(),
                'beatsWithCount' => collect(),
                'latestArticleDate' => null,
            ]);
        }

        $articles = Article::with([
            'coverImage:id,article_id,file_url,is_cover',
            'category:id,name,slug'
        ])
        ->select(self::LIST_COLUMNS)
        ->where('user_id', $user->id)
        ->whereRaw('LOWER(status) = ?', ['published'])
        ->where('is_archived', false)
        ->latest('published_at')
        ->get();

        $roleName = $user->role->role_name ?? 'Staff Contributor';
        $totalViews = (int) $articles->sum('views');
        $beatsWithCount = $articles->groupBy(fn ($a) => $a->category->name ?? 'General')
            ->map(fn ($group) => $group->count());
        $beats = $beatsWithCount->keys();
        $latestArticleDate = $articles->first()?->published_at ?? $articles->first()?->created_at;

        return view('author', [
            'author' => $user,
            'authorName' => $user->full_name,
            'articles' => $articles,
            'roleName' => $roleName,
            'totalViews' => $totalViews,
            'beats' => $beats,
            'beatsWithCount' => $beatsWithCount,
            'latestArticleDate' => $latestArticleDate ? $latestArticleDate->format('M d, Y') : null,
        ]);
    }

    /**
     * Display the About Us page.
     */
    public function about(): View
    {
        return view('about');
    }

    /**
     * Display the Members / Editorial Board directory page.
     */
    public function members(): View
    {
        return view('members');
    }

    /**
     * Display the Feedback form page.
     */
    public function feedback(): View
    {
        return view('feedback');
    }

    /**
     * Handle Feedback form submission.
     */
    public function submitFeedback(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:150',
            'category' => 'required|string|in:general,editorial,bug,suggestion,other',
            'message' => 'required|string|max:2000',
        ]);

        Feedback::create($validated);

        return redirect()->route('public.feedback')
            ->with('success', 'Thank you! Your feedback has been submitted successfully.');
    }
}
