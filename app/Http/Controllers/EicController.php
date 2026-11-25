<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Category;
use App\Models\User;
use App\Models\Role;
use App\Models\Feedback;
use App\Models\ActivityLog;
use App\Services\ArticleImageService;
use App\Traits\LogsActivity;
use Illuminate\Support\Facades\Hash;

class EicController extends Controller
{
    use LogsActivity;

    public function __construct(
        protected ArticleImageService $imageService
    ) {}
    public function dashboard()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $user?->loadMissing('role');

        // Cache consolidated dashboard metrics for 30s to keep navigation instantaneous
        $stats = Cache::remember('eic_dashboard_stats', 30, function () {
            return Article::selectRaw("
                COALESCE(SUM(views), 0) as total_views,
                COUNT(CASE WHEN status = 'Published' THEN 1 END) as published_stories,
                COUNT(CASE WHEN (status = 'Pending EIC Review' AND user_id NOT IN (
                    SELECT u.id FROM users u 
                    JOIN roles r ON u.role_id = r.id 
                    WHERE r.role_name IN ('Editor-in-Chief', 'Associate Editor-in-Chief')
                )) OR (status IN ('For Revision', 'For Writer Revision', 'For Head Revision', 'For Section Head Revision', 'Returned for Revision')) THEN 1 END) as pending
            ")->first();
        });

        $activeMembers = Cache::remember('eic_active_members', 60, function () {
            return User::where(function ($q) {
                $q->where('account_status', 'active')
                  ->orWhere('account_status', '!=', 'deactivated')
                  ->orWhereNull('account_status');
            })->count();
        });

        $metrics = [
            'total_views' => (int) ($stats->total_views ?? 0),
            'published_stories' => (int) ($stats->published_stories ?? 0),
            'pending' => (int) ($stats->pending ?? 0),
            'active_members' => $activeMembers,
        ];

        $pendingArticles = Cache::remember('eic_dashboard_pending_articles', 30, function () {
            $revisionStatuses = ['For Revision', 'For Writer Revision', 'For Head Revision', 'For Section Head Revision', 'Returned for Revision'];
            return Article::with(['user.role', 'category'])
                ->where(function ($q) use ($revisionStatuses) {
                    $q->where(function ($subQ) {
                        $subQ->where('status', 'Pending EIC Review')
                             ->whereDoesntHave('user.role', function ($r) {
                                 $r->whereIn('role_name', ['Editor-in-Chief', 'Associate Editor-in-Chief']);
                             })
                             ->where('user_id', '!=', Auth::id());
                    })->orWhereIn('status', $revisionStatuses);
                })
                ->latest()
                ->take(5)
                ->get()
                ->map(function ($art) {
                    return [
                        'id' => (string) $art->id,
                        'title' => $art->title,
                        'author' => $art->user->full_name ?? 'Author',
                        'author_avatar' => $art->user?->avatar_url ?? ($art->user?->avatar ? asset($art->user->avatar) : asset('images/member-placeholder.svg')),
                        'category' => $art->category->name ?? 'General',
                        'status' => $art->status,
                        'remarks' => $art->remarks ?? '',
                        'date' => $art->created_at->format('M d, Y'),
                        'content' => $art->content,
                        'excerpt' => $art->excerpt,
                    ];
                });
        });

        $publishedArticles = Cache::remember('eic_dashboard_published_articles', 30, function () {
            return Article::with(['user.role', 'category'])
                ->where('status', 'Published')
                ->orderByDesc('views')
                ->take(5)
                ->get()
                ->map(function ($art) {
                    return [
                        'id' => (string) $art->id,
                        'title' => $art->title,
                        'author' => $art->user->full_name ?? 'Author',
                        'category' => $art->category->name ?? 'General',
                        'status' => $art->status,
                        'date' => $art->created_at->format('M d, Y'),
                        'content' => $art->content,
                        'excerpt' => $art->excerpt,
                        'views' => (int) ($art->views ?? 0),
                        'shares' => (int) ($art->shares ?? 0),
                    ];
                });
        });

        $recentActivities = ActivityLog::with(['user.role', 'entity'])
            ->latest()
            ->take(5)
            ->get()
            ->map->formatForTimeline();

        $topJournalists = Cache::remember('eic_dashboard_top_journalists', 60, function () {
            return Article::where('status', 'Published')
                ->selectRaw('user_id, COUNT(*) as article_count')
                ->groupBy('user_id')
                ->orderByDesc('article_count')
                ->take(5)
                ->with('user.role')
                ->get()
                ->map(function ($row) {
                    return [
                        'id' => $row->user_id,
                        'name' => $row->user->full_name ?? 'Author',
                        'role' => $row->user->role->role_name ?? 'Writer',
                        'avatar' => $row->user->avatar_url ?? ($row->user->avatar ? asset($row->user->avatar) : asset('images/member-placeholder.svg')),
                        'article_count' => (int) $row->article_count,
                    ];
                });
        });

        if (request()->wantsJson() || request()->is('api/*')) {
            return response()->json([
                'user' => (new AuthController)->formatUserData($user),
                'metrics' => $metrics,
                'pendingArticles' => $pendingArticles,
                'publishedArticles' => $publishedArticles,
                'recentActivities' => $recentActivities,
                'topJournalists' => $topJournalists,
            ]);
        }

        return view('eic.dashboard', compact('metrics', 'pendingArticles', 'publishedArticles', 'recentActivities', 'topJournalists'));
    }

    public function manageArticles(Request $request)
    {
        $articles = Cache::remember('eic_articles_for_management', 30, function () {
            return Article::with(['user.role', 'category', 'coverImage'])
                ->where(function ($q) {
                    $q->whereRaw('LOWER(status) = ?', ['published'])
                      ->orWhereRaw('LOWER(status) = ?', ['archived'])
                      ->orWhere('is_archived', true);
                })
                ->latest('published_at')
                ->get()
                ->map(function ($article) {
                    $authorAvatar = $article->user?->avatar_url ?? ($article->user?->avatar ? asset($article->user->avatar) : asset('images/member-placeholder.svg'));
                    $isArchived = (bool) ($article->is_archived || strtolower((string) $article->status) === 'archived');
                    return [
                        'id' => (int) $article->id,
                        'title' => $article->title,
                        'slug' => $article->slug,
                        'category' => $article->category->name ?? 'General',
                        'excerpt' => $article->excerpt ?? '',
                        'content' => $article->content ?? '',
                        'status' => $isArchived ? 'Archived' : 'Published',
                        'date' => $article->published_at ? $article->published_at->format('M d, Y') : $article->created_at->format('M d, Y'),
                        'rawDate' => ($article->published_at ?? $article->created_at)->toIso8601String(),
                        'views' => (int) ($article->views ?? 0),
                        'shares' => (int) ($article->shares ?? 0),
                        'readTime' => max(1, (int) ceil(str_word_count(strip_tags($article->content ?? '')) / 200)) . ' min read',
                        'author' => $article->user->full_name ?? 'Staff Writer',
                        'authorAvatar' => $authorAvatar,
                        'isArchived' => $isArchived,
                        'image' => $article->cover_url ?: asset('images/maroon.jpg'),
                    ];
                });
        });

        if ($request->wantsJson() || $request->ajax() || $request->is('api/*')) {
            return response()->json($articles);
        }

        return view('eic.manage-articles', compact('articles'));
    }

    public function archiveArticle(Request $request, int|string $id): JsonResponse
    {
        $article = Article::findOrFail($id);
        $article->is_archived = true;
        $article->status = 'archived';
        $article->save();

        Cache::forget('all_articles_for_management');
        Cache::forget('eic_articles_for_management');

        return response()->json([
            'success' => true,
            'message' => 'Article moved to archive successfully.',
        ]);
    }

    public function restoreArticle(Request $request, int|string $id): JsonResponse
    {
        $article = Article::findOrFail($id);
        $article->is_archived = false;
        $article->status = 'published';
        $article->save();

        Cache::forget('all_articles_for_management');
        Cache::forget('eic_articles_for_management');

        return response()->json([
            'success' => true,
            'message' => 'Article restored to active published articles successfully.',
        ]);
    }

    public function updateArticle(Request $request, int|string $id): JsonResponse
    {
        $article = Article::findOrFail($id);

        if ($request->filled('title')) {
            $article->title = (string) $request->input('title');
            $article->slug = \Illuminate\Support\Str::slug((string) $request->input('title'));
        }
        if ($request->has('excerpt')) {
            $article->excerpt = Article::cleanArticleBodyText((string) $request->input('excerpt'));
        }
        if ($request->has('content') || $request->has('body')) {
            $article->content = Article::cleanArticleBodyText((string) ($request->input('content') ?? $request->input('body')));
        }
        if ($request->filled('category')) {
            $catInput = trim((string) $request->input('category'));
            $cat = Category::where('name', $catInput)
                ->orWhere('slug', \Illuminate\Support\Str::slug($catInput))
                ->orWhere('name', 'like', "%{$catInput}%")
                ->first();

            if (!$cat) {
                if (stripos($catInput, 'sci') !== false || stripos($catInput, 'tech') !== false) {
                    $cat = Category::where('name', 'like', '%Sci%')->first();
                } elseif (stripos($catInput, 'feat') !== false) {
                    $cat = Category::where('name', 'like', '%Feat%')->first();
                } elseif (stripos($catInput, 'opin') !== false) {
                    $cat = Category::where('name', 'like', '%Opin%')->first();
                } elseif (stripos($catInput, 'lit') !== false) {
                    $cat = Category::where('name', 'like', '%Liter%')->first();
                } elseif (stripos($catInput, 'news') !== false) {
                    $cat = Category::where('name', 'like', '%News%')->first();
                }
            }

            if ($cat) {
                $article->category_id = $cat->id;
            }
        }
        $article->save();

        Cache::forget('all_articles_for_management');
        Cache::forget('eic_articles_for_management');
        Article::clearArticleCaches($article);

        // Reload category name for JSON response
        $article->loadMissing('category');

        return response()->json([
            'success' => true,
            'message' => 'Article updated successfully.',
            'article' => [
                'id' => (int) $article->id,
                'title' => $article->title,
                'slug' => $article->slug,
                'category' => $article->category?->name ?? 'General',
                'excerpt' => $article->excerpt,
                'content' => $article->content,
            ],
        ]);
    }

    public function destroyArticle(Request $request, int|string $id): JsonResponse
    {
        $article = Article::findOrFail($id);

        // Emergency Delete is a strict hard-delete: purge related image assets and article record permanently
        $article->images()->delete();
        $article->delete();

        Cache::forget('all_articles_for_management');
        Cache::forget('eic_articles_for_management');
        Article::clearArticleCaches($article);

        return response()->json([
            'success' => true,
            'message' => 'Article permanently deleted from database.',
        ]);
    }

    public function manageMyArticles()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $user?->loadMissing('role');

        $articles = Article::with(['category', 'user.role'])
            ->where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($article) use ($user) {
                return [
                    'id' => $article->id,
                    'title' => $article->title,
                    'category' => $article->category->name ?? 'General',
                    'excerpt' => $article->excerpt,
                    'content' => $article->content,
                    'status' => $article->status,
                    'date' => $article->created_at->format('M d, Y'),
                    'rawDate' => $article->created_at->toIso8601String(),
                    'views' => $article->views ?? 0,
                    'shares' => $article->shares ?? 0,
                    'remarks' => $article->remarks,
                    'author' => $user->full_name ?? ($article->user->full_name ?? 'Staff Writer'),
                ];
            });

        if (request()->wantsJson() || request()->is('api/*')) {
            return response()->json($articles);
        }

        return view('eic.manage-my-articles', compact('articles'));
    }

    /**
     * Map categories to the exact format: '(Last name) (category head)'.
     *
     * @return array<string, string>
     */
    protected function getCategoryHeadsMap(): array
    {
        return Cache::remember('category_section_heads_map_v5', 3600, function () {
            // Default mapping with confirmed Section Heads from database
            $map = [
                'News' => 'Arquero (News Head)',
                'Literature' => 'Llandelar (Literary Head)',
                'Literary' => 'Llandelar (Literary Head)',
                'Feature' => 'Dela Cruz (Feature Head)',
                'Features' => 'Dela Cruz (Feature Head)',
                'Science & Technology' => 'Apilado (SciTech Head)',
                'Science and Technology' => 'Apilado (SciTech Head)',
                'SciTech' => 'Apilado (SciTech Head)',
                'Opinion' => 'Direct Submission (Opinion)',
                'Opinions' => 'Direct Submission (Opinion)',
            ];

            try {
                $headUsers = User::with('role')
                    ->whereHas('role', function ($q) {
                        $q->where('role_name', 'like', '%Head%');
                    })
                    ->get();

                foreach ($headUsers as $user) {
                    $roleName = $user->role->role_name ?? '';
                    $lastName = $this->extractLastName($user->full_name);

                    if (stripos($roleName, 'News') !== false) {
                        $map['News'] = "{$lastName} (News Head)";
                    } elseif (stripos($roleName, 'Literary') !== false || stripos($roleName, 'Literature') !== false) {
                        $map['Literature'] = "{$lastName} (Literary Head)";
                        $map['Literary'] = "{$lastName} (Literary Head)";
                    } elseif (stripos($roleName, 'Feature') !== false) {
                        $map['Feature'] = "{$lastName} (Feature Head)";
                        $map['Features'] = "{$lastName} (Feature Head)";
                    } elseif (stripos($roleName, 'Science') !== false) {
                        $map['Science & Technology'] = "{$lastName} (SciTech Head)";
                        $map['Science and Technology'] = "{$lastName} (SciTech Head)";
                        $map['SciTech'] = "{$lastName} (SciTech Head)";
                    }
                    // Note: No Opinion Head exists in publication organization
                }
            } catch (\Throwable $e) {
                // If database query is unavailable, fallback map is used
            }

            return $map;
        });
    }

    /**
     * Extract the surname from a full name, accounting for Filipino compound surnames.
     */
    protected function extractLastName(?string $fullName): string
    {
        if (empty($fullName)) return '';
        $parts = preg_split('/\s+/', trim($fullName));
        if (count($parts) === 1) return $parts[0];

        $last = array_pop($parts);
        $prev = end($parts);
        // Handle compound surnames like 'Dela Cruz', 'De Castro', 'Delos Santos', 'San Juan'
        if ($prev && in_array(strtolower($prev), ['de', 'dela', 'del', 'delos', 'san'])) {
            return $prev . ' ' . $last;
        }

        return $last;
    }

    /**
     * Resolve the Section Head who approved or is responsible for an article based on category or remarks.
     *
     * @param  \App\Models\Article  $article
     * @param  array<string, string>  $categoryHeadsMap
     * @return string
     */
    protected function resolveEndorserForArticle($article, array $categoryHeadsMap): string
    {
        $authorRole = $article->user?->role?->role_name ?? '';
        $executiveRoles = [
            'President', 'Executive Vice President', 'Vice President',
            'Secretary', 'Associate Secretary', 'Treasurer', 'Auditor',
            'Public Relations Officer', 'Public Relations', 'Executive',
        ];
        foreach ($executiveRoles as $execRole) {
            if (stripos($authorRole, $execRole) !== false) {
                return 'Direct Submission (' . $authorRole . ')';
            }
        }

        $categoryName = $article->category->name ?? '';

        // 1. For Opinion articles, there is no Opinion Head -> Direct Submission
        if (!empty($categoryName) && stripos($categoryName, 'opinion') !== false) {
            return 'Direct Submission (Opinion)';
        }

        // 2. Strictly resolve the Section Head based on the submitted article's category
        if (!empty($categoryName)) {
            if (isset($categoryHeadsMap[$categoryName])) {
                return $categoryHeadsMap[$categoryName];
            }
            foreach ($categoryHeadsMap as $catKey => $headVal) {
                if (stripos($categoryName, $catKey) !== false || stripos($catKey, $categoryName) !== false) {
                    return $headVal;
                }
            }
        }

        // 3. Fallback: check remarks if category was not set
        if (!empty($article->remarks)) {
            if (preg_match('/(?:Endorsed to EIC by|Approved by(?: Section Head)?:?)\s*([^\r\n]+)/i', $article->remarks, $matches)) {
                $matched = trim($matches[1]);
                if (!empty($matched)) {
                    return $matched;
                }
            }
        }

        // 4. Fallback if category was not set: check if author themselves is a Section Head
        $authorRole = $article->user->role->role_name ?? '';
        if (stripos($authorRole, 'Head') !== false) {
            $lastName = $this->extractLastName($article->user->full_name ?? '');
            return "{$lastName} ({$authorRole})";
        }

        return $article->endorser ?? 'Section Head';
    }

    public function reviewArticles(Request $request)
    {
        $sort = $request->query('sort', 'newest');
        $userId = Auth::id() ?? 0;
        $cacheKey = 'eic_review_articles_' . $userId . '_' . $sort;
        $categoryHeads = $this->getCategoryHeadsMap();

        $pendingArticles = Cache::remember($cacheKey, 30, function () use ($sort, $categoryHeads, $userId) {
            $query = Article::with(['user.role', 'category'])
                ->where('status', 'Pending EIC Review')
                ->whereDoesntHave('user.role', function ($q) {
                    $q->whereIn('role_name', ['Editor-in-Chief', 'Associate Editor-in-Chief']);
                })
                ->where('user_id', '!=', $userId);

            if ($sort === 'oldest') {
                $query->oldest();
            } else {
                $query->latest();
            }

            return $query->get()->map(function ($article) use ($categoryHeads) {
                $endorsedBy = $this->resolveEndorserForArticle($article, $categoryHeads);

                return [
                    'id' => $article->id,
                    'user_id' => $article->user_id,
                    'title' => $article->title,
                    'category' => $article->category->name ?? 'General',
                    'category_id' => $article->category_id,
                    'excerpt' => $article->excerpt,
                    'content' => $article->content,
                    'status' => $article->status,
                    'date' => $article->created_at->format('M d, Y'),
                    'rawDate' => $article->created_at->toIso8601String(),
                    'author' => $article->user->full_name ?? 'Staff Writer',
                    'author_avatar' => $article->user->avatar ?? asset('images/member-placeholder.svg'),
                    'remarks' => $article->remarks,
                    'endorsedBy' => $endorsedBy,
                ];
            });
        });

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'pendingArticles' => $pendingArticles,
                'sort' => $sort,
                'categoryHeads' => $categoryHeads,
            ]);
        }

        return view('eic.review-articles', compact('pendingArticles', 'sort', 'categoryHeads'));
    }

    public function updateReviewArticle(Request $request, $id)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'excerpt' => 'nullable|string|max:1000',
        ]);

        $article = Article::findOrFail($id);

        if ($article->user_id === Auth::id()) {
            return response()->json(['message' => 'Unauthorized: You cannot review or approve your own manuscript.'], 403);
        }

        $cleanContent = $this->sanitizeArticleContent($request->content);

        $article->update([
            'title' => $request->title,
            'excerpt' => $request->excerpt ?: \Illuminate\Support\Str::limit(strip_tags($cleanContent), 160),
            'content' => $cleanContent,
        ]);

        ActivityLog::logAction(Auth::id(), 'edited manuscript', $article);

        Cache::forget('eic_dashboard_stats');
        Cache::forget('eic_dashboard_pending_articles');
        Cache::forget('eic_review_articles_newest');
        Cache::forget('eic_review_articles_oldest');
        Cache::forget('mpc_dashboard_stats');
        Cache::forget('mpc_dashboard_pending_articles');
        Cache::forget('mpc_dashboard_published_articles');

        return response()->json([
            'success' => true,
            'message' => 'Article changes saved successfully.',
            'article' => [
                'id' => $article->id,
                'title' => $article->title,
                'excerpt' => $article->excerpt,
                'content' => $article->content,
            ],
        ]);
    }

    public function approveArticle(Request $request, $id)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $user?->loadMissing('role');

        $article = Article::findOrFail($id);

        if ($article->user_id === Auth::id()) {
            return response()->json(['message' => 'Unauthorized: You cannot review or approve your own manuscript.'], 403);
        }

        // If updated title or content are passed with the approve request, persist them as well
        if ($request->filled('title') && $request->filled('content')) {
            $cleanContent = $this->sanitizeArticleContent($request->content);
            $article->update([
                'title' => $request->title,
                'excerpt' => $request->excerpt ?: \Illuminate\Support\Str::limit(strip_tags($cleanContent), 160),
                'content' => $cleanContent,
            ]);
        }

        $article->update([
            'status' => 'Approved',
        ]);

        ActivityLog::logAction(Auth::id(), 'approved', $article);

        Cache::forget('eic_dashboard_stats');
        Cache::forget('eic_dashboard_pending_articles');
        Cache::forget('eic_review_articles_newest');
        Cache::forget('eic_review_articles_oldest');
        Cache::forget('mpc_dashboard_stats');
        Cache::forget('mpc_dashboard_pending_articles');
        Cache::forget('mpc_dashboard_published_articles');

        if ($request->wantsJson() || $request->ajax() || $request->is('api/*')) {
            return response()->json(['success' => true, 'message' => 'Article approved and forwarded to Media & Publication Committee.']);
        }

        return redirect()->back()->with('success', 'Article approved.');
    }

    public function returnForRevision(Request $request, $id)
    {
        $request->validate([
            'remarks' => 'required|string',
        ]);

        $article = Article::with('category')->findOrFail($id);

        if ($article->user_id === Auth::id()) {
            return response()->json(['message' => 'Unauthorized: You cannot return your own manuscript for revision.'], 403);
        }

        $catName = $article->category->name ?? '';
        $isOpinion = stripos($catName, 'opinion') !== false;

        $authorRole = $article->user?->role?->role_name ?? '';
        $isExecutiveAuthor = false;
        foreach (['President', 'Vice President', 'Executive Vice President', 'Secretary', 'Associate Secretary', 'Treasurer', 'Auditor', 'Public Relations', 'Executive'] as $exec) {
            if (stripos($authorRole, $exec) !== false) {
                $isExecutiveAuthor = true;
                break;
            }
        }

        $targetStatus = ($isOpinion || $isExecutiveAuthor) ? 'For Writer Revision' : 'For Head Revision';
        $message = $isExecutiveAuthor
            ? 'Article returned to author for writer revision.'
            : ($isOpinion
                ? 'Opinion article returned to author for writer revision.'
                : "Article returned to {$catName} Head for head revision.");

        $article->update([
            'status' => $targetStatus,
            'remarks' => $request->remarks,
        ]);

        ActivityLog::logAction(Auth::id(), 'returned for revision', $article);

        Article::clearArticleCaches($article);
        Cache::forget('eic_dashboard_stats');
        Cache::forget('eic_dashboard_pending_articles');
        Cache::forget('eic_review_articles_newest');
        Cache::forget('eic_review_articles_oldest');
        Cache::forget('mpc_dashboard_stats');
        Cache::forget('mpc_dashboard_pending_articles');
        Cache::forget('mpc_dashboard_published_articles');

        if ($request->wantsJson() || $request->ajax() || $request->is('api/*')) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return redirect()->back()->with('warning', $message);
    }

    /**
     * Media Desk View (PUBMAT, Photos, Cartoons, and Library Review)
     */
    public function mediaDesk()
    {
        $pending = ArticleImage::whereIn('status', [
                'pending_eic',
                'Pending EIC Review',
                'pending_head',
                'pending',
                'Pending with Head',
                'Pending Review',
            ])
            ->with(['uploader.role', 'headApprover.role'])
            ->latest()
            ->get();

        $approved = ArticleImage::approved()
            ->with(['uploader.role', 'headApprover.role', 'eicApprover.role', 'article'])
            ->latest()
            ->get();

        $coverList = $pending->merge($approved)
            ->map(fn($item) => $this->imageService->formatForView($item))
            ->values()
            ->all();

        if (request()->wantsJson() || request()->is('api/*')) {
            return response()->json(['coverList' => $coverList]);
        }

        return view('eic.media-desk', compact('coverList'));
    }

    /**
     * Approve visual artwork into publication library
     */
    public function approveMedia(int|string $id, Request $request)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $image = ArticleImage::findOrFail($id);

        $this->imageService->approveByEic($image, $user);
        ActivityLog::logAction($user->id, 'approved visual artwork');

        Cache::forget('mpc_dashboard_stats');

        if ($request->wantsJson() || $request->ajax() || $request->is('api/*')) {
            return response()->json([
                'success' => true,
                'message' => 'Visual asset approved into publication library.',
                'cover' => $this->imageService->formatForView($image),
            ]);
        }

        return redirect()->back()->with('success', 'Visual asset approved into publication library.');
    }

    /**
     * Return visual artwork to section head / artist for revision
     */
    public function returnMediaForRevision(int|string $id, Request $request)
    {
        $request->validate([
            'remarks' => 'required|string',
        ]);

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $image = ArticleImage::findOrFail($id);

        $this->imageService->returnForRevision($image, $user, (string) $request->remarks);
        ActivityLog::logAction($user->id, 'returned visual asset for revision');

        if ($request->wantsJson() || $request->ajax() || $request->is('api/*')) {
            return response()->json([
                'success' => true,
                'message' => 'Visual asset returned for revision.',
                'cover' => $this->imageService->formatForView($image),
            ]);
        }

        return redirect()->back()->with('success', 'Visual asset returned for revision.');
    }

    public function submitArticle(Request $request)
    {
        $categories = Cache::remember('all_categories', 86400, fn() => Category::all());

        $drafts = Article::with(['category', 'user.role'])
            ->where('user_id', Auth::id())
            ->whereIn('status', ['Draft', 'For Revision'])
            ->latest()
            ->get();

        $editingDraft = null;
        if ($request->has('draft_id')) {
            $editingDraft = Article::with(['category', 'user.role'])
                ->where('user_id', Auth::id())
                ->whereIn('status', ['Draft', 'For Revision'])
                ->find($request->draft_id);
        }

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'categories' => $categories,
                'drafts' => $drafts,
                'editingDraft' => $editingDraft,
            ]);
        }

        return view('eic.submit-article', compact('categories', 'drafts', 'editingDraft'));
    }

    public function storeArticle(Request $request)
    {
        $rules = [
            'title' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'status' => 'required|string',
            'article_id' => 'nullable|integer'
        ];

        if ($request->status === 'Pending Review' || $request->status === 'Pending EIC Review') {
            $rules['excerpt'] = 'required|string|max:255';
            $rules['content'] = 'required|string';
        } else {
            $rules['excerpt'] = 'nullable|string|max:255';
            $rules['content'] = 'nullable|string';
        }

        $request->validate($rules);

        $cleanContent = $this->sanitizeArticleContent($request->content);

        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::user();
        $currentUser?->loadMissing('role');
        $currentUserRole = $currentUser?->role?->role_name ?? '';
        $isEicOrAeic = in_array($currentUserRole, ['Editor-in-Chief', 'Associate Editor-in-Chief']);

        $targetStatus = $request->status === 'Draft' 
            ? 'Draft' 
            : ($isEicOrAeic ? 'Pending President Review' : 'Pending EIC Review');

        $successMsg = $targetStatus === 'Draft'
            ? 'Draft saved successfully.'
            : ($isEicOrAeic
                ? 'Article submitted and forwarded to President for approval.'
                : 'Article submitted and forwarded to review queue.');

        try {
            if ($request->filled('article_id')) {
                $article = Article::where('user_id', Auth::id())->find($request->article_id);
                if ($article) {
                    $isRevision = in_array(strtolower(trim((string)$article->status)), [
                        'for revision', 'for writer revision', 'for head revision',
                        'for section head revision', 'returned for revision', 'revision',
                    ]) || stripos((string)$article->status, 'revision') !== false;

                    if ($isRevision && $targetStatus !== 'Draft') {
                        if (!Article::isBodyModified($article->content, $cleanContent)) {
                            return response()->json([
                                'success' => false,
                                'message' => 'You cannot submit an article returned for revision without editing the article body. Please revise the manuscript before submitting.',
                            ], 422);
                        }
                    }

                    $article->update([
                        'category_id' => $request->category_id,
                        'title' => $request->title,
                        'excerpt' => $request->excerpt,
                        'content' => $cleanContent,
                        'status' => $targetStatus,
                        'remarks' => null,
                    ]);

                    if ($targetStatus === 'Pending EIC Review' || $targetStatus === 'Pending President Review') {
                        ActivityLog::logAction(Auth::id(), 'submitted', $article);
                    }

                    Cache::forget('eic_dashboard_stats');
                    Cache::forget('eic_dashboard_pending_articles');
                    Cache::forget('eic_review_articles_newest');
                    Cache::forget('eic_review_articles_oldest');
                    Cache::forget('executive_dashboard_stats_President');
                    Cache::forget('executive_dashboard_stats_pres_vp');
                    Cache::forget('executive_dashboard_stats_all');
                    Cache::forget('exec_dashboard_pending_President');
                    Cache::forget('exec_dashboard_pending_pres_vp');
                    Cache::forget('exec_dashboard_pending_all');
                    Cache::forget('exec_review_articles_President_newest');
                    Cache::forget('exec_review_articles_President_oldest');
                    Cache::forget('exec_review_articles_' . $request->category_id . '_newest');
                    Cache::forget('exec_review_articles_' . $request->category_id . '_oldest');
                    Cache::forget('exec_review_articles_all_newest');
                    Cache::forget('exec_review_articles_all_oldest');

                    if ($request->wantsJson() || $request->is('api/*')) {
                        return response()->json([
                            'success' => true,
                            'message' => $successMsg,
                            'article' => $article,
                        ]);
                    }

                    if ($targetStatus === 'Draft') {
                        return redirect()->route('eic.submit-article', ['tab' => 'drafts'])
                            ->with('success', 'Draft saved successfully.');
                    }
                    return redirect()->route('eic.dashboard')
                        ->with('success', $successMsg);
                }
            }

            $article = Article::create([
                'user_id' => Auth::id(),
                'category_id' => $request->category_id,
                'title' => $request->title,
                'excerpt' => $request->excerpt,
                'content' => $cleanContent,
                'status' => $targetStatus,
                'views' => 0,
                'shares' => 0,
            ]);

            if ($targetStatus === 'Pending EIC Review' || $targetStatus === 'Pending President Review') {
                ActivityLog::logAction(Auth::id(), 'submitted', $article);
            }

            Cache::forget('eic_dashboard_stats');
            Cache::forget('eic_dashboard_pending_articles');
            Cache::forget('eic_review_articles_newest');
            Cache::forget('eic_review_articles_oldest');
            Cache::forget('executive_dashboard_stats_President');
            Cache::forget('executive_dashboard_stats_pres_vp');
            Cache::forget('executive_dashboard_stats_all');
            Cache::forget('exec_dashboard_pending_President');
            Cache::forget('exec_dashboard_pending_pres_vp');
            Cache::forget('exec_dashboard_pending_all');
            Cache::forget('exec_review_articles_President_newest');
            Cache::forget('exec_review_articles_President_oldest');
            Cache::forget('exec_review_articles_' . $request->category_id . '_newest');
            Cache::forget('exec_review_articles_' . $request->category_id . '_oldest');
            Cache::forget('exec_review_articles_all_newest');
            Cache::forget('exec_review_articles_all_oldest');

            if ($request->wantsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => true,
                    'message' => $successMsg,
                    'article' => $article,
                ]);
            }

            if ($targetStatus === 'Draft') {
                return redirect()->route('eic.submit-article', ['tab' => 'drafts'])
                    ->with('success', 'Draft saved successfully.');
            }

            return redirect()->route('eic.dashboard')
                ->with('success', $successMsg);

        } catch (\Exception $e) {
            if ($request->wantsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Database Error: ' . $e->getMessage()], 422);
            }
            return back()->withErrors(['system_error' => 'Database Error: ' . $e->getMessage()])->withInput();
        }
    }

    public function destroyDraft($id)
    {
        $draft = Article::where('user_id', Auth::id())
            ->where('status', 'Draft')
            ->findOrFail($id);

        $draft->delete();

        if (request()->wantsJson() || request()->is('api/*')) {
            return response()->json(['success' => true, 'message' => 'Draft deleted successfully.']);
        }

        return redirect()->route('eic.submit-article', ['tab' => 'drafts'])->with('success', 'Draft deleted successfully.');
    }

    public function manageAccounts(Request $request)
    {
        // Fetch all accounts including Writers, Cartoonists, Media Team, and Executive/Editorial board
        $officers = Cache::remember('all_officers_list', 60, function () {
            return User::with('role')
                ->orderBy('full_name', 'asc')
                ->get();
        });

        $roles = Cache::remember('all_roles_list', 3600, function () {
            return Role::orderBy('role_name', 'asc')
                ->get();
        });

        $formattedOfficers = $officers->map(function ($officer) {
            return [
                'id' => (string) $officer->id,
                'name' => $officer->full_name,
                'studentNumber' => $officer->student_number ?? '2024-00000-BN-0',
                'role' => $officer->role->role_name ?? 'Staff',
                'avatar' => $officer->avatar_url ?? ($officer->avatar ? asset($officer->avatar) : asset('images/member-placeholder.svg')),
                'status' => $officer->account_status ?? 'active',
            ];
        });

        if ($request->wantsJson() || $request->ajax() || $request->is('api/*')) {
            return response()->json([
                'success' => true,
                'officers' => $formattedOfficers,
                'roles' => $roles,
            ]);
        }

        return view('eic.manage-accounts', compact('officers', 'roles', 'formattedOfficers'));
    }

    public function storeAccount(Request $request)
    {
        if (!$request->filled('student_number') && $request->filled('studentNumber')) {
            $request->merge(['student_number' => $request->input('studentNumber')]);
        }
        if (!$request->filled('full_name') && $request->filled('name')) {
            $request->merge(['full_name' => $request->input('name')]);
        }
        if ($request->filled('student_number')) {
            $request->merge(['student_number' => strtoupper(trim((string) $request->input('student_number')))]);
        }

        $roleId = $request->input('role_id');
        if (!$roleId && $request->filled('role')) {
            $roleVal = $request->input('role');
            $roleObj = is_numeric($roleVal)
                ? Role::find($roleVal)
                : Role::where('role_name', $roleVal)->first();
            $roleId = $roleObj?->id;
        }
        $request->merge(['role_id' => $roleId]);

        $validated = $request->validate([
            'student_number' => [
                'required',
                'string',
                'regex:/^\d{4}-\d{5}-BN-0$/',
                'unique:users,student_number',
            ],
            'full_name' => 'required|string|max:255',
            'role_id' => 'required|exists:roles,id',
        ], [
            'student_number.required' => 'Student number is required.',
            'student_number.regex' => 'The student number format must be YEAR-5DIGIT-BN-0 (e.g. 2024-00010-BN-0).',
            'student_number.unique' => 'This student number is already registered to another account.',
            'full_name.required' => 'Full name is required.',
            'role_id.required' => 'Please select a valid role.',
            'role_id.exists' => 'The selected role is invalid.',
        ]);

        $officer = User::create([
            'student_number' => $validated['student_number'],
            'full_name' => $validated['full_name'],
            'role_id' => $validated['role_id'],
            'password' => Hash::make('thelenspupBN0'),
            'account_status' => 'active',
        ]);

        ActivityLog::logAction(Auth::id(), 'created account for', $officer);

        Cache::forget('all_officers_list');
        Cache::forget('eic_active_members');
        Cache::forget('active_writers_count');
        Cache::forget('exec_active_members_count');
        Cache::forget('mpc_active_members');

        $officer->load('role');

        $formatted = [
            'id' => (string) $officer->id,
            'name' => $officer->full_name,
            'studentNumber' => $officer->student_number ?? '2024-00000-BN-0',
            'role' => $officer->role->role_name ?? 'Staff',
            'avatar' => $officer->avatar_url ?? ($officer->avatar ? asset($officer->avatar) : asset('images/member-placeholder.svg')),
            'status' => $officer->account_status ?? 'active',
        ];

        if ($request->wantsJson() || $request->ajax() || $request->is('api/*')) {
            return response()->json([
                'success' => true,
                'message' => 'Officer account created successfully. Default password is thelenspupBN0.',
                'officer' => $formatted,
            ]);
        }

        return redirect()->back()->with('success', 'Officer account created successfully. Default password is thelenspupBN0.');
    }

    public function updateRole(Request $request, $id)
    {
        $officer = User::findOrFail($id);

        $roleId = $request->input('role_id');
        if (!$roleId && $request->filled('role')) {
            $roleVal = $request->input('role');
            $roleObj = is_numeric($roleVal)
                ? Role::find($roleVal)
                : Role::where('role_name', $roleVal)->first();
            $roleId = $roleObj?->id;
        }
        $request->merge(['role_id' => $roleId]);

        $validated = $request->validate([
            'role_id' => 'required|exists:roles,id',
        ]);

        $officer->update([
            'role_id' => $validated['role_id'],
        ]);

        \App\Auth\CachedEloquentUserProvider::clearCache($officer->id);
        Cache::forget('all_officers_list');
        Cache::forget('eic_active_members');
        Cache::forget('active_writers_count');
        Cache::forget('exec_active_members_count');
        Cache::forget('mpc_active_members');

        $officer->load('role');

        ActivityLog::logAction(Auth::id(), 'updated role for', $officer);

        $formatted = [
            'id' => (string) $officer->id,
            'name' => $officer->full_name,
            'studentNumber' => $officer->student_number ?? '2024-00000-BN-0',
            'role' => $officer->role->role_name ?? 'Staff',
            'avatar' => $officer->avatar_url ?? ($officer->avatar ? asset($officer->avatar) : asset('images/member-placeholder.svg')),
            'status' => $officer->account_status ?? 'active',
        ];

        if ($request->wantsJson() || $request->ajax() || $request->is('api/*')) {
            return response()->json([
                'success' => true,
                'message' => "Role for {$officer->full_name} updated to {$officer->role->role_name}.",
                'officer' => $formatted,
            ]);
        }

        return redirect()->back()->with('success', "Role for {$officer->full_name} updated to {$officer->role->role_name}.");
    }

    public function toggleStatus(Request $request, $id)
    {
        $officer = User::findOrFail($id);

        $status = $request->input('status');
        if (!$status) {
            $status = ($officer->account_status === 'deactivated') ? 'active' : 'deactivated';
        }

        if (!in_array($status, ['active', 'deactivated'])) {
            $status = 'active';
        }

        if ($officer->id === Auth::id() && $status === 'deactivated') {
            if ($request->wantsJson() || $request->ajax() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot deactivate your own active account.',
                ], 422);
            }
            return redirect()->back()->with('error', 'You cannot deactivate your own active account.');
        }

        $officer->update([
            'account_status' => $status,
        ]);

        $statusLabel = ($status === 'active') ? 'reactivated' : 'deactivated';

        ActivityLog::logAction(Auth::id(), $statusLabel, $officer);

        \App\Auth\CachedEloquentUserProvider::clearCache($officer->id);
        Cache::forget('all_officers_list');
        Cache::forget('eic_active_members');
        Cache::forget('active_writers_count');
        Cache::forget('exec_active_members_count');
        Cache::forget('mpc_active_members');

        $officer->load('role');

        $formatted = [
            'id' => (string) $officer->id,
            'name' => $officer->full_name,
            'studentNumber' => $officer->student_number ?? '2024-00000-BN-0',
            'role' => $officer->role->role_name ?? 'Staff',
            'avatar' => $officer->avatar_url ?? ($officer->avatar ? asset($officer->avatar) : asset('images/member-placeholder.svg')),
            'status' => $officer->account_status ?? 'active',
        ];

        if ($request->wantsJson() || $request->ajax() || $request->is('api/*')) {
            return response()->json([
                'success' => true,
                'message' => "Officer account {$statusLabel}.",
                'status' => $status,
                'officer' => $formatted,
            ]);
        }

        return redirect()->back()->with('success', "Officer account {$statusLabel}.");
    }

    public function activityLogs()
    {
        $activities = ActivityLog::with(['user.role', 'entity'])
            ->latest()
            ->take(100)
            ->get()
            ->map->formatForTimeline();

        if (request()->wantsJson() || request()->is('api/*')) {
            return response()->json($activities);
        }

        return view('eic.activity-logs', compact('activities'));
    }

    public function feedbackInbox(\App\Services\FeedbackService $feedbackService)
    {
        $feedbacks = $feedbackService->getInboxMessages();
        if (request()->wantsJson() || request()->is('api/*')) {
            return response()->json(['feedbacks' => $feedbacks]);
        }

        return view('eic.feedback-inbox', compact('feedbacks'));
    }

    public function markFeedbackRead($id, \App\Services\FeedbackService $feedbackService)
    {
        $feedback = $feedbackService->markAsRead((int) $id);

        return response()->json([
            'success' => true,
            'id' => $feedback->id,
            'is_read' => (bool) $feedback->is_read,
        ]);
    }

    public function profileSettings()
    {
        if (request()->wantsJson() || request()->is('api/*')) {
            return response()->json(['user' => (new AuthController)->formatUserData(Auth::user())]);
        }

        return view('eic.profile-settings');
    }

    private function sanitizeArticleContent($content)
    {
        return Article::cleanArticleBodyText($content);
    }
}

