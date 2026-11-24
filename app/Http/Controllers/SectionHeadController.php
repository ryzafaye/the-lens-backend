<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\Models\Article;
use App\Models\Category;
use App\Models\ActivityLog;

class SectionHeadController extends ExecutiveController
{
    public function dashboard()
    {
        $request = request();
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $user?->loadMissing('role');
        $userRole = $user?->role?->role_name ?? 'Section Head';
        $category = $this->getCategoryForUser($user);

        // Fetch metrics for Section Head
        $query = Article::query();
        if ($category) {
            $query->where('category_id', $category->id);
        } else {
            $query->whereHas('category', fn($q) => $q->where('name', '!=', 'Opinion'));
        }

        $userId = Auth::id() ?? 0;
        $catKey = $category->id ?? 'all';
        $statsCacheKey = "section_head_dashboard_stats_{$userId}_{$catKey}";

        $stats = Cache::remember($statsCacheKey, 30, function () use ($query, $userId) {
            return (clone $query)->selectRaw("
                COALESCE(SUM(views), 0) as total_views,
                COUNT(CASE WHEN status = 'Published' THEN 1 END) as published_stories,
                COUNT(CASE WHEN ((status IN ('Pending Head Review', 'Pending Review') AND user_id != {$userId}) OR (status IN ('For Head Revision', 'For Section Head Revision', 'For Revision', 'Returned for Revision', 'For Writer Revision'))) THEN 1 END) as pending
            ")->first();
        });

        $activeMembers = Cache::remember('active_members_count', 300, function () {
            return \App\Models\User::where(function ($q) {
                $q->where('account_status', 'active')
                  ->orWhere('account_status', '!=', 'deactivated')
                  ->orWhereNull('account_status');
            })->count();
        });

        $metrics = [
            'total_views' => (int) ($stats->total_views ?? 0),
            'monthly_views' => (int) ($stats->total_views ?? 0),
            'published_stories' => (int) ($stats->published_stories ?? 0),
            'pending' => (int) ($stats->pending ?? 0),
            'active_members' => $activeMembers,
        ];

        $pendingCacheKey = "section_head_dashboard_pending_{$userId}_{$catKey}";
        $pendingArticles = Cache::remember($pendingCacheKey, 30, function () use ($category, $userId) {
            $submissionsQuery = Article::with(['user.role', 'category:id,name'])
                ->select(['id', 'title', 'slug', 'category_id', 'user_id', 'content', 'excerpt', 'status', 'remarks', 'views', 'shares', 'created_at'])
                ->where(function ($q) use ($userId) {
                    $q->where(function ($sq) use ($userId) {
                        $sq->whereIn('status', ['Pending Head Review', 'Pending Review'])
                           ->where('user_id', '!=', $userId);
                    })->orWhere(function ($sq) {
                        $sq->whereIn('status', ['For Head Revision', 'For Section Head Revision', 'For Revision', 'Returned for Revision', 'For Writer Revision']);
                    });
                });
            if ($category) {
                $submissionsQuery->where('category_id', $category->id);
            } else {
                $submissionsQuery->whereHas('category', fn($q) => $q->where('name', '!=', 'Opinion'));
            }
            return $submissionsQuery->latest()->take(5)->get()->map(function ($art) {
                return [
                    'id' => (string) $art->id,
                    'title' => $art->title,
                    'author' => $art->user->full_name ?? 'Author',
                    'author_avatar' => $art->user?->avatar_url ?? ($art->user?->avatar ? asset($art->user->avatar) : asset('images/member-placeholder.svg')),
                    'category' => $art->category->name ?? 'General',
                    'status' => $art->status,
                    'remarks' => $art->remarks ?? '',
                    'date' => $art->created_at->format('M d, Y'),
                    'rawDate' => $art->created_at?->toIso8601String(),
                    'content' => $art->content,
                    'excerpt' => $art->excerpt,
                    'views' => (int) ($art->views ?? 0),
                    'shares' => (int) ($art->shares ?? 0),
                ];
            });
        });

        $publishedCacheKey = "section_head_dashboard_published_{$catKey}";
        $publishedArticles = Cache::remember($publishedCacheKey, 300, function () use ($category) {
            $pubQuery = Article::with(['user.role', 'category:id,name'])
                ->select(['id', 'title', 'slug', 'category_id', 'user_id', 'content', 'excerpt', 'status', 'views', 'shares', 'created_at'])
                ->where('status', 'Published');
            if ($category) {
                $pubQuery->where('category_id', $category->id);
            }
            return $pubQuery->orderByDesc('views')->take(5)->get()->map(function ($art) {
                return [
                    'id' => (string) $art->id,
                    'title' => $art->title,
                    'author' => $art->user->full_name ?? 'Author',
                    'author_avatar' => $art->user?->avatar_url ?? ($art->user?->avatar ? asset($art->user->avatar) : asset('images/member-placeholder.svg')),
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

        $activitiesCacheKey = "section_head_dashboard_recent_activities";
        $recentActivities = Cache::remember($activitiesCacheKey, 300, function () {
            return ActivityLog::with(['user.role', 'entity'])
                ->latest()
                ->take(5)
                ->get()
                ->map(fn($log) => $log->formatForTimeline())
                ->values();
        });

        // Top Performing Journalists leaderboard
        $journalistsCacheKey = "section_head_dashboard_journalists_{$catKey}";
        $topJournalists = Cache::remember($journalistsCacheKey, 300, function () use ($category) {
            $query = Article::where('status', 'Published');
            if ($category) {
                $query->where('category_id', $category->id);
            }
            return $query->selectRaw('user_id, COUNT(*) as article_count')
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

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'user' => (new AuthController)->formatUserData($user),
                'metrics' => $metrics,
                'pendingArticles' => $pendingArticles,
                'publishedArticles' => $publishedArticles,
                'category' => $category?->name,
                'recentActivities' => $recentActivities,
                'topJournalists' => $topJournalists,
            ]);
        }

        $dashboardData = [
            'metrics' => $metrics,
            'pendingArticles' => $pendingArticles,
            'articles' => $publishedArticles,
            'category' => $category?->name,
            'recentActivities' => $recentActivities,
        ];

        return view('executive.dashboard', compact('metrics', 'pendingArticles', 'publishedArticles', 'category', 'dashboardData', 'recentActivities'));
    }

    public function manageMyArticles()
    {
        $request = request();
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $user?->loadMissing('role');

        $userId = Auth::id();
        $articles = Cache::remember("section_head_manage_articles_{$userId}", 300, function () use ($user, $userId) {
            return Article::with(['category:id,name', 'user.role'])
                ->where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($article) use ($user) {
                    $status = $article->status;
                    $canEdit = ($status === 'Draft' || $status === 'For Revision');
                    return [
                        'id' => $article->id,
                        'title' => $article->title,
                        'category' => $article->category->name ?? 'General',
                        'category_id' => $article->category_id,
                        'excerpt' => $article->excerpt,
                        'content' => $article->content,
                        'status' => $article->status,
                        'date' => $article->created_at->format('M d, Y'),
                        'rawDate' => $article->created_at->toIso8601String(),
                        'views' => (int)($article->views ?? 0),
                        'shares' => (int)($article->shares ?? 0),
                        'remarks' => $article->remarks,
                        'author' => $user?->full_name ?? ($article->user->full_name ?? 'Staff Writer'),
                        'author_avatar' => $user?->avatar_url ?? $article->user?->avatar_url ?? ($article->user?->avatar ? asset($article->user->avatar) : asset('images/member-placeholder.svg')),
                        'canEdit' => $canEdit,
                    ];
                });
        });

        $totalViews = $articles->where('status', 'Published')->sum('views');

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'user' => (new AuthController)->formatUserData($user),
                'articles' => $articles,
                'totalViews' => $totalViews,
            ]);
        }

        return view('executive.manage-my-articles', compact('articles'));
    }

    protected function getCategoryForUser($user)
    {
        if (!$user) return null;
        
        $roleName = (new AuthController)->getUserRoleName($user);
        if (empty($roleName)) {
            $user->loadMissing('role');
            $roleName = $user->role->role_name ?? '';
        }

        $cachedCategories = Cache::remember('all_categories', 86400, fn() => Category::all());

        if (stripos($roleName, 'News') !== false) {
            return $cachedCategories->first(fn($c) => stripos($c->name, 'News') !== false);
        }
        if (stripos($roleName, 'Literary') !== false || stripos($roleName, 'Literature') !== false) {
            return $cachedCategories->first(fn($c) => stripos($c->name, 'Liter') !== false);
        }
        if (stripos($roleName, 'Feature') !== false) {
            return $cachedCategories->first(fn($c) => stripos($c->name, 'Feature') !== false);
        }
        if (stripos($roleName, 'Science') !== false || stripos($roleName, 'SciTech') !== false) {
            return $cachedCategories->first(fn($c) => stripos($c->name, 'Science') !== false);
        }

        return null;
    }

    public function reviewArticles(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $user?->loadMissing('role');
        $category = $this->getCategoryForUser($user);

        $sort = $request->query('sort', 'newest');
        $catKey = $category->id ?? 'general';
        $userId = Auth::id() ?? 0;
        $cacheKey = "section_head_review_articles_{$userId}_{$catKey}_{$sort}";

        $pendingArticles = Cache::remember($cacheKey, 300, function () use ($category, $sort, $userId) {
            $query = Article::with(['user:id,full_name,profile_picture_url,role_id', 'category:id,name'])
                ->whereIn('status', ['Pending Head Review', 'Pending Review'])
                ->where('user_id', '!=', $userId);

            // STRICT: Section head can only see articles under their assigned section!
            if ($category) {
                $query->where('category_id', $category->id);
            } else {
                $query->whereHas('category', fn($q) => $q->where('name', '!=', 'Opinion'));
            }

            if ($sort === 'oldest') {
                $query->oldest();
            } else {
                $query->latest();
            }

            return $query->get()->map(function ($article) {
                return [
                    'id' => (string) $article->id,
                    'title' => $article->title,
                    'category' => $article->category->name ?? 'General',
                    'category_id' => $article->category_id,
                    'excerpt' => $article->excerpt,
                    'content' => $article->content,
                    'status' => $article->status,
                    'date' => $article->created_at->format('M d, Y'),
                    'rawDate' => $article->created_at->toIso8601String(),
                    'author' => $article->user->full_name ?? 'Staff Writer',
                    'author_avatar' => $article->user?->avatar_url ?? ($article->user?->avatar ? asset($article->user->avatar) : asset('images/member-placeholder.svg')),
                    'remarks' => $article->remarks,
                ];
            });
        });

        $revCacheKey = "section_head_revision_articles_{$userId}_{$catKey}_{$sort}";
        $revisionArticles = Cache::remember($revCacheKey, 300, function () use ($category, $sort, $userId) {
            $query = Article::with(['user:id,full_name,profile_picture_url,role_id', 'category:id,name'])
                ->whereIn('status', ['For Head Revision', 'For Section Revision', 'Section Revision']);

            // STRICT: Section head can only see revision articles under their assigned section!
            if ($category) {
                $query->where('category_id', $category->id);
            } else {
                $query->whereHas('category', fn($q) => $q->where('name', '!=', 'Opinion'));
            }

            if ($sort === 'oldest') {
                $query->oldest();
            } else {
                $query->latest();
            }

            return $query->get()->map(function ($article) {
                return [
                    'id' => (string) $article->id,
                    'title' => $article->title,
                    'category' => $article->category->name ?? 'General',
                    'category_id' => $article->category_id,
                    'excerpt' => $article->excerpt,
                    'content' => $article->content,
                    'status' => $article->status,
                    'date' => $article->updated_at ? $article->updated_at->format('M d, Y') : $article->created_at->format('M d, Y'),
                    'rawDate' => $article->updated_at ? $article->updated_at->toIso8601String() : $article->created_at->toIso8601String(),
                    'author' => $article->user->full_name ?? 'Staff Writer',
                    'author_avatar' => $article->user?->avatar_url ?? ($article->user?->avatar ? asset($article->user->avatar) : asset('images/member-placeholder.svg')),
                    'remarks' => $article->remarks,
                ];
            });
        });

        // Compute category counts for tabs in a single optimized query
        if ($category) {
            $count = Article::whereIn('status', ['Pending Head Review', 'Pending Review'])
                ->where('user_id', '!=', $userId)
                ->where('category_id', $category->id)
                ->count();

            $categoriesList = [$category->name];
            $categoryCounts = [
                'all' => $count,
                $category->name => $count,
            ];
        } else {
            $categoryCountsKey = "section_head_review_cat_counts_{$userId}";
            $categoryCountsData = Cache::remember($categoryCountsKey, 300, function () use ($userId) {
                $categoriesList = Category::where('name', '!=', 'Opinion')->pluck('name', 'id')->toArray();
                $countsByCat = Article::whereIn('status', ['Pending Head Review', 'Pending Review'])
                    ->where('user_id', '!=', $userId)
                    ->selectRaw('category_id, COUNT(*) as cnt')
                    ->groupBy('category_id')
                    ->pluck('cnt', 'category_id')
                    ->toArray();

                $categoryCounts = [];
                $totalPending = 0;
                foreach ($categoriesList as $catId => $catName) {
                    $catCnt = (int) ($countsByCat[$catId] ?? 0);
                    $categoryCounts[$catName] = $catCnt;
                    $totalPending += $catCnt;
                }
                $categoryCounts['all'] = $totalPending;

                return [
                    'list' => array_values($categoriesList),
                    'counts' => $categoryCounts,
                ];
            });

            $categoriesList = $categoryCountsData['list'];
            $categoryCounts = $categoryCountsData['counts'];
        }

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'user' => (new AuthController)->formatUserData($user),
                'pendingArticles' => $pendingArticles,
                'revisionArticles' => $revisionArticles,
                'revisionCount' => count($revisionArticles),
                'category' => $category?->name,
                'sort' => $sort,
                'categoryCounts' => $categoryCounts,
                'categories' => $categoriesList,
            ]);
        }

        return view('executive.review-articles', compact('pendingArticles', 'revisionArticles', 'category', 'sort'));
    }

    public function updateReviewArticle(Request $request, $id)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'excerpt' => 'nullable|string|max:1000',
        ]);

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $user?->loadMissing('role');
        $category = $this->getCategoryForUser($user);

        $article = Article::with('category')->findOrFail($id);

        // Strict authorization: A section head can only edit articles under their assigned section!
        if ($category && $article->category_id !== $category->id) {
            $catName = $category->name;
            $msg = "Unauthorized: As {$catName} Head, you can only edit manuscripts under the {$catName} section.";
            if ($request->wantsJson() || $request->is('api/*') || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $msg,
                ], 403);
            }
            abort(403, $msg);
        }

        $cleanContent = $this->sanitizeArticleContent($request->content);

        $article->update([
            'title' => $request->title,
            'excerpt' => $request->excerpt ?: \Illuminate\Support\Str::limit(strip_tags($cleanContent), 160),
            'content' => $cleanContent,
        ]);

        ActivityLog::logAction(Auth::id(), 'edited manuscript', $article);

        $this->clearAllRelatedCaches($article->category_id);

        if ($request->wantsJson() || $request->is('api/*')) {
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

        return redirect()->back()->with('success', 'Article changes saved successfully.');
    }

    public function approveArticle(Request $request, $id)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $user?->loadMissing('role');
        $category = $this->getCategoryForUser($user);

        $article = Article::with('category')->findOrFail($id);

        // Strict authorization: A section head can ONLY approve articles under their assigned section!
        if ($category && $article->category_id !== $category->id) {
            $catName = $category->name;
            $msg = "Unauthorized: As {$catName} Head, you can only approve articles under the {$catName} section.";
            if ($request->wantsJson() || $request->is('api/*') || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $msg,
                ], 403);
            }
            abort(403, $msg);
        }

        if ($request->filled('title') && $request->filled('content')) {
            $cleanContent = $this->sanitizeArticleContent($request->content);
            $article->update([
                'title' => $request->title,
                'excerpt' => $request->excerpt ?: \Illuminate\Support\Str::limit(strip_tags($cleanContent), 160),
                'content' => $cleanContent,
            ]);
        }

        // Section Head approval endorses story to EIC, AEIC, President, and VP
        $article->update([
            'status' => 'Pending EIC Review',
        ]);

        ActivityLog::logAction(Auth::id(), 'approved', $article);

        $this->clearAllRelatedCaches($article->category_id);

        if ($request->wantsJson() || $request->is('api/*') || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Article approved and forwarded to Editor-in-Chief (EIC).',
            ]);
        }

        return redirect()->back()->with('success', 'Article approved and forwarded to Editor-in-Chief.');
    }

    public function returnForRevision(Request $request, $id)
    {
        $request->validate([
            'remarks' => 'required|string',
        ]);

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $user?->loadMissing('role');
        $category = $this->getCategoryForUser($user);

        $article = Article::with('category')->findOrFail($id);

        // Strict authorization: A section head can ONLY return articles under their assigned section!
        if ($category && $article->category_id !== $category->id) {
            $catName = $category->name;
            $msg = "Unauthorized: As {$catName} Head, you can only review articles under the {$catName} section.";
            if ($request->wantsJson() || $request->is('api/*') || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $msg,
                ], 403);
            }
            abort(403, $msg);
        }

        $article->update([
            'status' => 'For Writer Revision',
            'remarks' => $request->remarks,
        ]);

        ActivityLog::logAction(Auth::id(), 'returned for revision', $article);

        $this->clearAllRelatedCaches($article->category_id);

        if ($request->wantsJson() || $request->is('api/*') || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Article returned to author for writer revision.',
            ]);
        }

        return redirect()->back()->with('warning', 'Article returned to author for writer revision.');
    }

    public function submitArticle(Request $request)
    {
        $userId = Auth::id();
        $categories = Cache::remember('all_categories', 86400, fn() => Category::all());

        $drafts = Cache::remember("section_head_drafts_{$userId}", 30, function () use ($userId) {
            return Article::with(['category', 'user.role'])
                ->where('user_id', $userId)
                ->whereIn('status', ['Draft', 'For Revision'])
                ->latest()
                ->get()
                ->map(function ($d) {
                    return [
                        'id' => $d->id,
                        'title' => $d->title,
                        'category' => $d->category->name ?? 'General',
                        'category_id' => $d->category_id,
                        'excerpt' => $d->excerpt,
                        'content' => $d->content,
                        'status' => $d->status,
                        'remarks' => $d->remarks,
                        'date' => $d->created_at->format('M d, Y'),
                    ];
                });
        });

        $editingDraft = null;
        if ($request->has('draft_id')) {
            $rawDraft = Article::with(['category', 'user.role'])
                ->where('user_id', $userId)
                ->whereIn('status', ['Draft', 'For Revision'])
                ->find($request->draft_id);
            if ($rawDraft) {
                $editingDraft = [
                    'id' => $rawDraft->id,
                    'title' => $rawDraft->title,
                    'category' => $rawDraft->category->name ?? 'General',
                    'category_id' => $rawDraft->category_id,
                    'excerpt' => $rawDraft->excerpt,
                    'content' => $rawDraft->content,
                    'status' => $rawDraft->status,
                    'remarks' => $rawDraft->remarks,
                    'date' => $rawDraft->created_at->format('M d, Y'),
                ];
            }
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();
        if ($user) $user->loadMissing('role');

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'user' => (new AuthController)->formatUserData($user),
                'categories' => $categories,
                'drafts' => $drafts,
                'editingDraft' => $editingDraft,
            ]);
        }

        return view('executive.submit-article', compact('categories', 'drafts', 'editingDraft'));
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

        // Section Head submissions bypass section head review and go directly to EIC/AEIC for approval
        $targetStatus = $request->status === 'Draft' ? 'Draft' : 'Pending EIC Review';

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

                    $this->clearAllRelatedCaches($request->category_id);

                    if ($targetStatus === 'Pending EIC Review') {
                        ActivityLog::logAction(Auth::id(), 'submitted', $article);
                    }

                    $msg = $targetStatus === 'Draft' ? 'Draft saved successfully.' : 'Article submitted and forwarded to Editor-in-Chief for approval.';

                    if ($request->wantsJson() || $request->is('api/*')) {
                        return response()->json([
                            'success' => true,
                            'message' => $msg,
                            'article' => $article,
                        ]);
                    }

                    if ($targetStatus === 'Draft') {
                        return redirect()->route('section-head.submit-article', ['tab' => 'drafts'])
                            ->with('success', 'Draft saved successfully.');
                    }
                    return redirect()->route('section-head.manage-my-articles')
                        ->with('success', $msg);
                }
            }

            $createdArticle = Article::create([
                'user_id' => Auth::id(),
                'category_id' => $request->category_id,
                'title' => $request->title,
                'excerpt' => $request->excerpt,
                'content' => $cleanContent,
                'status' => $targetStatus,
                'views' => 0,
                'shares' => 0,
            ]);

            if ($targetStatus === 'Pending EIC Review') {
                ActivityLog::logAction(Auth::id(), 'submitted', $createdArticle);
            }

            $this->clearAllRelatedCaches($request->category_id);

            $msg = $targetStatus === 'Draft' ? 'Draft saved successfully.' : 'Article submitted and forwarded to Editor-in-Chief for approval.';

            if ($request->wantsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => true,
                    'message' => $msg,
                    'article' => $createdArticle,
                ]);
            }

            if ($targetStatus === 'Draft') {
                return redirect()->route('section-head.submit-article', ['tab' => 'drafts'])
                    ->with('success', 'Draft saved successfully.');
            }

            return redirect()->route('section-head.manage-my-articles')
                ->with('success', $msg);

        } catch (\Exception $e) {
            if ($request->wantsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Database Error: ' . $e->getMessage()], 500);
            }
            return back()->withErrors(['system_error' => 'Database Error: ' . $e->getMessage()])->withInput();
        }
    }

    public function destroyDraft($id)
    {
        $draft = Article::where('user_id', Auth::id())
            ->whereIn('status', ['Draft', 'For Revision'])
            ->findOrFail($id);

        $categoryId = $draft->category_id;
        $draft->delete();
        $this->clearAllRelatedCaches($categoryId);

        if (request()->wantsJson() || request()->is('api/*')) {
            return response()->json(['success' => true, 'message' => 'Draft deleted successfully.']);
        }

        return redirect()->route('section-head.submit-article', ['tab' => 'drafts'])->with('success', 'Draft deleted successfully.');
    }

    public function activityLogs()
    {
        $request = request();
        $query = ActivityLog::with(['user.role', 'entity'])->latest();

        if ($request->filled('search')) {
            $search = strtolower($request->search);
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(action) LIKE ?', ["%{$search}%"])
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->whereRaw('LOWER(full_name) LIKE ?', ["%{$search}%"])
                        ->orWhereHas('role', function ($rq) use ($search) {
                            $rq->whereRaw('LOWER(role_name) LIKE ?', ["%{$search}%"]);
                        });
                  })
                  ->orWhereHasMorph('entity', [\App\Models\Article::class], function ($eq) use ($search) {
                      $eq->whereRaw('LOWER(title) LIKE ?', ["%{$search}%"]);
                  });
            });
        }

        if ($request->filled('date_filter')) {
            $filter = $request->date_filter;
            if ($filter === 'today') {
                $query->whereDate('created_at', now()->toDateString());
            } elseif ($filter === '7days') {
                $query->where('created_at', '>=', now()->subDays(7));
            } elseif ($filter === '30days') {
                $query->where('created_at', '>=', now()->subDays(30));
            } elseif ($filter === 'specific' && $request->filled('date')) {
                $query->whereDate('created_at', $request->date);
            }
        }

        $cacheKey = "section_head_activities_" . md5(json_encode($request->all()));
        $activities = Cache::remember($cacheKey, 15, function () use ($query) {
            return $query->take(100)->get()->map(fn($log) => $log->formatForTimeline())->values();
        });

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'activities' => $activities,
            ]);
        }

        return view('executive.activity-logs', compact('activities'));
    }

    public function profileSettings()
    {
        return view('executive.profile-settings');
    }

    protected function clearAllRelatedCaches($categoryId = null)
    {
        $userId = Auth::id() ?? 0;
        Cache::forget("section_head_dashboard_stats_{$userId}");
        Cache::forget("section_head_recent_articles_{$userId}");
        Cache::forget("section_head_manage_articles_{$userId}");
        Cache::forget("section_head_drafts_{$userId}");
        Cache::forget("executive_dashboard_stats_{$userId}");
        Cache::forget("executive_dashboard_stats_all");
        Cache::forget("executive_dashboard_stats_pres_vp");
        Cache::forget("exec_dashboard_pending_all");
        Cache::forget("exec_dashboard_pending_pres_vp");
        Cache::forget("eic_dashboard_stats");
        Cache::forget("eic_dashboard_pending_articles");
        Cache::forget("eic_review_articles_newest");
        Cache::forget("eic_review_articles_oldest");
        Cache::forget("exec_review_articles_all_newest");
        Cache::forget("exec_review_articles_all_oldest");
        if ($categoryId) {
            Cache::forget("exec_dashboard_pending_{$categoryId}");
            Cache::forget("executive_dashboard_stats_{$categoryId}");
            Cache::forget("exec_review_articles_{$categoryId}_newest");
            Cache::forget("exec_review_articles_{$categoryId}_oldest");
            Cache::forget("section_head_review_articles_{$userId}_{$categoryId}_newest");
            Cache::forget("section_head_review_articles_{$userId}_{$categoryId}_oldest");
            Cache::forget("section_head_revision_articles_{$userId}_{$categoryId}_newest");
            Cache::forget("section_head_revision_articles_{$userId}_{$categoryId}_oldest");
            Cache::forget("section_head_dashboard_stats_{$userId}_{$categoryId}");
            Cache::forget("section_head_dashboard_pending_{$userId}_{$categoryId}");
            Cache::forget("section_head_dashboard_published_{$categoryId}");
            Cache::forget("section_head_dashboard_journalists_{$categoryId}");
        }
        Cache::forget("section_head_review_articles_{$userId}_all_newest");
        Cache::forget("section_head_review_articles_{$userId}_all_oldest");
        Cache::forget("section_head_revision_articles_{$userId}_all_newest");
        Cache::forget("section_head_revision_articles_{$userId}_all_oldest");
        Cache::forget("section_head_review_cat_counts_{$userId}");
        Cache::forget("section_head_dashboard_stats_{$userId}_all");
        Cache::forget("section_head_dashboard_pending_{$userId}_all");
        Cache::forget("section_head_dashboard_published_all");
        Cache::forget("section_head_dashboard_journalists_all");
    }

    public function resubmitArticle(Request $request, $id)
    {
        $article = Article::with(['category', 'user.role'])->findOrFail($id);
        $user = Auth::user();
        $userId = $user->id;
        $roleName = $user->role?->role_name ?? '';
        $isPresOrVp = in_array($roleName, ['President', 'Vice President', 'Executive Vice President']);
        $isEicOrAeic = in_array($roleName, ['Editor-in-Chief', 'Associate Editor-in-Chief']);
        $isSectionHead = str_ends_with(strtolower($roleName), 'head') && !str_contains(strtolower($roleName), 'cartoonist') && !str_contains(strtolower($roleName), 'photojournalist');

        $canResubmit = ($article->user_id === $userId) || $isPresOrVp || $isEicOrAeic || ($isSectionHead && $article->category_id === $this->getCategoryForUser($user)?->id);
        if (!$canResubmit) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: You do not have permission to resubmit this article.',
            ], 403);
        }

        $isRevision = in_array(strtolower(trim((string)$article->status)), [
            'for revision', 'for writer revision', 'for head revision',
            'for section head revision', 'returned for revision', 'revision',
        ]) || stripos((string)$article->status, 'revision') !== false;

        if (!$isRevision) {
            return response()->json([
                'success' => false,
                'message' => 'Article is not currently in revision status.',
            ], 400);
        }

        $newContent = $request->filled('content') ? $this->sanitizeArticleContent($request->content) : $article->content;

        if (!Article::isBodyModified($article->content, $newContent)) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot submit an article returned for revision without editing the article body. Please revise the manuscript before submitting.',
            ], 422);
        }

        // Determine destination status based on role and category
        $catName = $article->category?->name ?? '';
        $isOpinion = stripos($catName, 'opinion') !== false;
        $authorRole = $article->user?->role?->role_name ?? '';
        $isAuthorEic = in_array($authorRole, ['Editor-in-Chief', 'Associate Editor-in-Chief']);

        if ($isAuthorEic) {
            $targetStatus = 'Pending President Review';
        } elseif ($isOpinion || $isSectionHead || $isPresOrVp || $isEicOrAeic) {
            $targetStatus = 'Pending EIC Review';
        } else {
            $targetStatus = 'Pending Head Review';
        }

        if ($request->filled('title')) {
            $article->title = $request->title;
        }
        if ($request->has('excerpt')) {
            $article->excerpt = $request->excerpt ?: \Illuminate\Support\Str::limit(strip_tags($newContent), 160);
        }
        $article->content = $newContent;
        $article->status = $targetStatus;
        $article->remarks = null;
        $article->save();

        ActivityLog::logAction(Auth::id(), 'submitted', $article);

        $this->clearAllRelatedCaches($article->category_id);
        Cache::forget('eic_dashboard_stats');
        Cache::forget('eic_dashboard_pending_articles');
        Cache::forget('exec_dashboard_pending_pres_vp');
        Cache::forget('executive_dashboard_stats_President');
        Cache::forget('writer_dashboard_stats_' . $article->user_id);
        Cache::forget('writer_recent_articles_' . $article->user_id);

        return response()->json([
            'success' => true,
            'message' => 'Article resubmitted successfully for review.',
            'article' => [
                'id' => (string) $article->id,
                'title' => $article->title,
                'status' => $article->status,
            ],
        ]);
    }

    protected function sanitizeArticleContent($content)
    {
        return Article::cleanArticleBodyText($content);
    }
}
