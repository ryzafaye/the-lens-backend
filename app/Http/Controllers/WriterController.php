<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\Models\Category;
use App\Models\Article;
use App\Models\ActivityLog;
use App\Traits\LogsActivity;

class WriterController extends Controller
{
    use LogsActivity;
    public function dashboard()
    {
        $userId = Auth::id() ?? 0;

        // Cached consolidated aggregate query for 5 minutes (invalidated automatically on article changes)
        $stats = Cache::remember('writer_dashboard_stats_' . $userId, 300, function () use ($userId) {
            return Article::where('user_id', $userId)->selectRaw("
                COALESCE(SUM(CASE WHEN LOWER(status) IN ('published', 'approved') THEN views ELSE 0 END), 0) as total_views,
                COUNT(CASE WHEN LOWER(status) IN ('published', 'approved') THEN 1 END) as published,
                COUNT(CASE WHEN LOWER(status) IN ('pending head review', 'pending review', 'pending eic review', 'for writer revision', 'for revision', 'revision') THEN 1 END) as pending,
                COUNT(CASE WHEN LOWER(status) IN ('pending head review', 'pending review', 'pending eic review') THEN 1 END) as pending_review,
                COUNT(CASE WHEN LOWER(status) IN ('for writer revision', 'for revision', 'revision') AND LOWER(status) NOT LIKE '%head%' AND LOWER(status) NOT LIKE '%section%' THEN 1 END) as for_revision,
                COUNT(CASE WHEN LOWER(status) = 'draft' THEN 1 END) as drafts
            ")->first();
        });

        $writerStats = [
            'total_views' => (int) ($stats->total_views ?? 0),
            'published' => (int) ($stats->published ?? 0),
            'pending' => (int) ($stats->pending ?? 0),
            'pending_review' => (int) ($stats->pending_review ?? 0),
            'for_revision' => (int) ($stats->for_revision ?? 0),
            'drafts' => (int) ($stats->drafts ?? 0),
        ];

        $recentArticles = Cache::remember('writer_recent_articles_' . $userId, 300, function () use ($userId) {
            return Article::with(['category:id,name', 'user:id,full_name,profile_picture_url,role_id'])
                ->select(['id', 'title', 'slug', 'category_id', 'user_id', 'excerpt', 'content', 'status', 'views', 'shares', 'created_at'])
                ->where('user_id', $userId)
                ->where(function ($q) {
                    $q->whereNull('status')
                      ->orWhereRaw("LOWER(status) NOT IN ('for head revision', 'for section revision', 'section revision')");
                })
                ->latest()
                ->take(5)
                ->get();
        });

        $topArticles = Cache::remember('writer_top_articles_' . $userId, 300, function () use ($userId) {
            return Article::with(['category:id,name', 'user:id,full_name,profile_picture_url,role_id'])
                ->select(['id', 'title', 'slug', 'category_id', 'user_id', 'excerpt', 'content', 'status', 'views', 'shares', 'created_at'])
                ->where('user_id', $userId)
                ->where(function ($q) {
                    $q->whereRaw("LOWER(status) = 'published'")
                      ->orWhereRaw("LOWER(status) = 'approved'");
                })
                ->orderByDesc('views')
                ->take(5)
                ->get();
        });

        if (request()->wantsJson() || request()->is('api/*')) {
            $user = Auth::user();
            return response()->json([
                'writerStats' => $writerStats,
                'user' => [
                    'id' => $user?->id,
                    'full_name' => $user?->full_name,
                    'first_name' => $user?->full_name ? explode(' ', $user->full_name)[0] : 'Writer',
                    'student_number' => $user?->student_number,
                    'role' => $user?->role?->role_name ?? 'Writer',
                    'avatar_url' => $user?->avatar_url,
                ],
                'recentArticles' => $recentArticles->map(function ($art) use ($user) {
                    $rawStatus = $art->status;
                    $statusLower = strtolower((string)$rawStatus);
                    $canEdit = in_array($rawStatus, ['Draft', 'For Revision', 'For Writer Revision']);

                    return [
                        'id' => $art->id,
                        'title' => $art->title,
                        'category' => $art->category->name ?? 'Uncategorized',
                        'excerpt' => $art->excerpt,
                        'content' => $art->content,
                        'status' => $rawStatus,
                        'views' => (int)($art->views ?? 0),
                        'shares' => (int)($art->shares ?? 0),
                        'date' => $art->created_at ? $art->created_at->format('M d, Y') : '',
                        'rawDate' => $art->created_at?->toIso8601String(),
                        'author' => $art->user?->full_name ?? ($user?->full_name ?? 'Writer'),
                        'author_avatar' => $art->user?->avatar_url ?? ($user?->avatar_url ?? null),
                        'canEdit' => $canEdit,
                    ];
                }),
                'topArticles' => $topArticles->map(function ($art) use ($user) {
                    return [
                        'id' => $art->id,
                        'title' => $art->title,
                        'category' => $art->category->name ?? 'General',
                        'excerpt' => $art->excerpt,
                        'content' => $art->content,
                        'status' => $art->status,
                        'views' => (int)($art->views ?? 0),
                        'shares' => (int)($art->shares ?? 0),
                        'date' => $art->created_at ? $art->created_at->format('M d, Y') : '',
                        'author' => $art->user?->full_name ?? ($user?->full_name ?? 'Writer'),
                        'author_avatar' => $art->user?->avatar_url ?? ($user?->avatar_url ?? null),
                    ];
                }),
            ]);
        }

        return view('writer.dashboard', compact('writerStats', 'recentArticles', 'topArticles'));
    }

    public function submitArticle(Request $request)
    {
        $userId = Auth::id();
        // Fetch categories from the database for the dropdown (cached)
        $categories = Cache::remember('all_categories', 86400, fn() => Category::all());
        $drafts = Cache::remember('writer_drafts_' . $userId, 300, function () use ($userId) {
            return Article::with(['category:id,name', 'user:id,full_name,role_id'])
                ->where('user_id', $userId)
                ->where('status', 'Draft')
                ->latest()
                ->get();
        });

        $editingDraft = null;
        if ($request->has('draft_id')) {
            $editingDraft = Article::with(['category', 'user.role'])
                ->where('user_id', Auth::id())
                ->where(function ($q) {
                    $q->where('status', 'Draft')
                      ->orWhere(function ($sq) {
                          $sq->whereIn('status', ['For Writer Revision', 'For Revision', 'Revision'])
                             ->where('status', 'not like', '%Head%')
                             ->where('status', 'not like', '%Section%');
                      });
                })
                ->find($request->draft_id);
        }

        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        if ($user) {
            $user->loadMissing('role');
        }

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'user' => $user ? (new AuthController)->formatUserData($user) : null,
                'categories' => $categories,
                'drafts' => $drafts->map(function ($d) {
                    return [
                        'id' => $d->id,
                        'title' => $d->title,
                        'category_id' => $d->category_id,
                        'category' => $d->category->name ?? 'Uncategorized',
                        'excerpt' => $d->excerpt,
                        'content' => $d->content,
                        'date' => $d->created_at ? $d->created_at->format('M d, Y') : '',
                    ];
                }),
                'editingDraft' => $editingDraft ? [
                    'id' => $editingDraft->id,
                    'title' => $editingDraft->title,
                    'category_id' => $editingDraft->category_id,
                    'excerpt' => $editingDraft->excerpt,
                    'content' => $editingDraft->content,
                    'status' => $editingDraft->status,
                ] : null,
            ]);
        }

        return view('writer.submit-article', compact('categories', 'drafts', 'editingDraft'));
    }

    public function manageMyArticles()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $user?->loadMissing('role');
        $userId = Auth::id();

        // Cached for 5 minutes (cleared on article changes)
        // Articles under Head Revision are strictly between EIC and Section Head and omitted from writer portal
        $articles = Cache::remember('writer_manage_articles_' . $userId, 300, function () use ($userId, $user) {
            return Article::with(['category:id,name', 'user:id,full_name,profile_picture_url,role_id'])
                ->where('user_id', $userId)
                ->where(function ($q) {
                    $q->whereNull('status')
                      ->orWhereRaw("LOWER(status) NOT IN ('for head revision', 'for section revision', 'section revision')");
                })
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
                        'author' => $article->user?->full_name ?? ($user?->full_name ?? 'Staff Writer'),
                        'author_avatar' => $article->user?->avatar_url ?? ($user?->avatar_url ?? null),
                    ];
                })->toArray();
        });

        if (request()->wantsJson() || request()->is('api/*')) {
            return response()->json([
                'user' => $user ? (new AuthController)->formatUserData($user) : null,
                'articles' => $articles,
            ]);
        }

        return view('writer.manage-my-articles', compact('articles'));
    }

    public function profileSettings()
    {
        return view('writer.profile-settings');
    }

    public function storeArticle(Request $request)
    {
        $rules = [
            'title' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id', // Now strictly required for both Draft and Review
            'status' => 'required|string', 
            'article_id' => 'nullable|integer'
        ];

        // Conditional validation for the rest
        if (in_array($request->status, ['Pending Review', 'Pending Head Review', 'Pending EIC Review'])) {
            $rules['excerpt'] = 'required|string|max:255';
            $rules['content'] = 'required|string';
        } else {
            $rules['excerpt'] = 'nullable|string|max:255';
            $rules['content'] = 'nullable|string';
        }

        $request->validate($rules);

        $cleanContent = $this->sanitizeArticleContent($request->content);

        // Opinion articles have no section head; submissions go directly to EIC/AEIC and President/VP
        $category = Category::find($request->category_id);
        $isOpinion = $category && stripos($category->name, 'opinion') !== false;

        $user = Auth::user();
        $user?->loadMissing('role');
        $roleName = $user?->role?->role_name ?? '';
        $isEicOrAeic = in_array($roleName, ['Editor-in-Chief', 'Associate Editor-in-Chief']);

        $isDraft = strtolower(trim((string) $request->input('status'))) === 'draft'
            || strtolower(trim((string) $request->input('action'))) === 'draft';

        $targetStatus = $isDraft
            ? 'Draft'
            : ($isEicOrAeic
                ? 'Pending President Review'
                : ($isOpinion || $request->status === 'Pending EIC Review' ? 'Pending EIC Review' : 'Pending Head Review'));

        // 2. ERROR CATCHING BLOCK
        try {
            // If article_id is present, update the existing draft
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

                    if (in_array($targetStatus, ['Pending Head Review', 'Pending Review', 'Pending EIC Review', 'Pending President Review'])) {
                        ActivityLog::logAction(Auth::id(), 'submitted', $article);
                    }

                    Cache::forget('writer_dashboard_stats_' . Auth::id());
                    Cache::forget('writer_recent_articles_' . Auth::id());
                    Cache::forget('eic_dashboard_stats');
                    Cache::put('article_workflow_cache_v', ((int) Cache::get('article_workflow_cache_v', 1)) + 1, 86400);
Cache::forget('eic_dashboard_pending_articles');
                    Cache::forget('eic_review_articles_newest');
                    Cache::forget('eic_review_articles_oldest');
                    Cache::forget('exec_dashboard_pending_pres_vp');
                    Cache::forget('exec_dashboard_pending_' . $request->category_id);
                    Cache::forget('exec_dashboard_pending_all');
                    Cache::forget('exec_review_articles_' . $request->category_id . '_newest');
                    Cache::forget('exec_review_articles_' . $request->category_id . '_oldest');
                    Cache::forget('exec_review_articles_all_newest');
                    Cache::forget('exec_review_articles_all_oldest');
                    Cache::forget('executive_dashboard_stats_President');
                    Cache::forget('exec_dashboard_pending_President');
                    Cache::forget('exec_review_articles_President_newest');
                    Cache::forget('exec_review_articles_President_oldest');

                    $this->clearWriterCache(Auth::id());
                    $this->clearSectionHeadCaches($request->category_id);

                    if ($targetStatus === 'Draft') {
                        if ($request->wantsJson() || $request->is('api/*')) {
                            return response()->json([
                                'success' => true,
                                'message' => 'Draft saved successfully.',
                                'article' => $article,
                            ]);
                        }
                        return redirect()->route('writer.submit-article', ['tab' => 'drafts'])
                            ->with('success', 'Draft saved successfully.');
                    }
                    $msg = $targetStatus === 'Pending President Review'
                        ? 'Article submitted and forwarded to President for approval.'
                        : ($isOpinion
                            ? 'Opinion submitted and forwarded directly to Editor-in-Chief review.'
                            : 'Article submitted successfully.');
                    if ($request->wantsJson() || $request->is('api/*')) {
                        return response()->json([
                            'success' => true,
                            'message' => $msg,
                            'article' => $article,
                        ]);
                    }
                    return redirect()->route('writer.dashboard')
                        ->with('success', $msg);
                }
            }

            // If new, create and save the article in the database
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

            if (in_array($targetStatus, ['Pending Head Review', 'Pending Review', 'Pending EIC Review', 'Pending President Review'])) {
                ActivityLog::logAction(Auth::id(), 'submitted', $article);
            }

            Cache::forget('writer_dashboard_stats_' . Auth::id());
            Cache::forget('writer_recent_articles_' . Auth::id());
            Cache::forget('eic_dashboard_stats');
            Cache::put('article_workflow_cache_v', ((int) Cache::get('article_workflow_cache_v', 1)) + 1, 86400);
Cache::forget('eic_dashboard_pending_articles');
            Cache::forget('eic_review_articles_newest');
            Cache::forget('eic_review_articles_oldest');
            Cache::forget('exec_dashboard_pending_pres_vp');
            Cache::forget('exec_dashboard_pending_' . $request->category_id);
            Cache::forget('exec_dashboard_pending_all');
            Cache::forget('exec_review_articles_' . $request->category_id . '_newest');
            Cache::forget('exec_review_articles_' . $request->category_id . '_oldest');
            Cache::forget('exec_review_articles_all_newest');
            Cache::forget('exec_review_articles_all_oldest');
            Cache::forget('executive_dashboard_stats_President');
            Cache::forget('exec_dashboard_pending_President');
            Cache::forget('exec_review_articles_President_newest');
            Cache::forget('exec_review_articles_President_oldest');

            $this->clearWriterCache(Auth::id());
            $this->clearSectionHeadCaches($request->category_id);

            if ($targetStatus === 'Draft') {
                if ($request->wantsJson() || $request->is('api/*')) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Draft saved successfully.',
                        'article' => $article,
                    ]);
                }
                return redirect()->route('writer.submit-article', ['tab' => 'drafts'])
                    ->with('success', 'Draft saved successfully.');
            }
            
            $msg = $targetStatus === 'Pending President Review'
                ? 'Article submitted and forwarded to President for approval.'
                : ($isOpinion
                    ? 'Opinion submitted and forwarded directly to Editor-in-Chief review.'
                    : 'Article submitted successfully.');
            if ($request->wantsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => true,
                    'message' => $msg,
                    'article' => $article,
                ]);
            }
            return redirect()->route('writer.dashboard')
                ->with('success', $msg);

        } catch (\Exception $e) {
            // If a database error occurs, return the error message with input
            if ($request->wantsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Database Error: ' . $e->getMessage()], 500);
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
        $this->clearWriterCache(Auth::id());

        if (request()->wantsJson() || request()->is('api/*')) {
            return response()->json(['success' => true, 'message' => 'Draft deleted successfully.']);
        }

        return redirect()->route('writer.submit-article', ['tab' => 'drafts'])->with('success', 'Draft deleted successfully.');
    }

    private function clearWriterCache(?int $userId = null): void
    {
        $id = $userId ?? Auth::id();
        if (!$id) return;
        Cache::forget('writer_manage_articles_' . $id);
        Cache::forget('writer_recent_articles_' . $id);
        Cache::forget('writer_top_articles_' . $id);
        Cache::forget('writer_dashboard_stats_' . $id);
        Cache::forget('writer_drafts_' . $id);
    }

    private function clearSectionHeadCaches(?int $categoryId = null): void
    {
        try {
            $sectionHeads = \App\Models\User::whereHas('role', function ($q) {
                $q->where('role_name', 'like', '%Section Head%')
                  ->orWhere('role_name', 'like', '%Head%')
                  ->orWhere('role_name', 'like', '%Editor%');
            })->pluck('id');

            foreach ($sectionHeads as $shId) {
                Cache::forget("section_head_dashboard_stats_{$shId}");
                Cache::forget("section_head_dashboard_stats_{$shId}_all");
                Cache::forget("section_head_dashboard_pending_{$shId}_all");
                Cache::forget("section_head_review_articles_{$shId}_all_newest");
                Cache::forget("section_head_review_articles_{$shId}_all_oldest");
                if ($categoryId) {
                    Cache::forget("section_head_dashboard_stats_{$shId}_{$categoryId}");
                    Cache::forget("section_head_dashboard_pending_{$shId}_{$categoryId}");
                    Cache::forget("section_head_review_articles_{$shId}_{$categoryId}_newest");
                    Cache::forget("section_head_review_articles_{$shId}_{$categoryId}_oldest");
                }
            }

            // Invalidate EIC and Executive caches so submissions (especially direct Opinion articles) immediately reflect
            Cache::forget('eic_dashboard_stats');
            Cache::put('article_workflow_cache_v', ((int) Cache::get('article_workflow_cache_v', 1)) + 1, 86400);
Cache::forget('eic_dashboard_pending_articles');
            Cache::forget('eic_review_articles_newest');
            Cache::forget('eic_review_articles_oldest');
            Cache::forget('exec_dashboard_pending_all');
            Cache::forget('exec_dashboard_pending_pres_vp');
            Cache::forget('executive_dashboard_stats_all');
            Cache::forget('executive_dashboard_stats_pres_vp');
            if ($categoryId) {
                Cache::forget("exec_dashboard_pending_{$categoryId}");
                Cache::forget("executive_dashboard_stats_{$categoryId}");
            }
        } catch (\Throwable $e) {}
    }

    /**
     * Sanitize content by stripping corrupted HTML entities, spans, and foreign inline styles.
     */
    private function sanitizeArticleContent($content)
    {
        return Article::cleanArticleBodyText($content);
    }
}