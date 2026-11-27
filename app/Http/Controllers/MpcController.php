<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Category;
use App\Models\User;
use App\Models\Role;
use App\Models\ActivityLog;
use App\Services\ArticleImageService;
use App\Traits\LogsActivity;

class MpcController extends Controller
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

        $stats = Cache::remember('mpc_dashboard_stats', 30, function () {
            return Article::selectRaw("
                COALESCE(SUM(views), 0) as total_views,
                COUNT(CASE WHEN status = 'Published' THEN 1 END) as published_stories,
                COUNT(CASE WHEN status IN ('Approved', 'Ready to Publish') THEN 1 END) as pending
            ")->first();
        });

        $activeMembers = Cache::remember('mpc_active_members', 60, function () {
            return User::where('account_status', 'active')->count();
        });

        $metrics = [
            'total_views' => (int) ($stats->total_views ?? 0),
            'monthly_views' => (int) ($stats->total_views ?? 0),
            'published_stories' => (int) ($stats->published_stories ?? 0),
            'pending' => (int) ($stats->pending ?? 0),
            'active_members' => $activeMembers,
        ];

        $pendingArticles = Cache::remember('mpc_dashboard_pending_articles', 30, function () {
            return Article::with(['user.role', 'category', 'coverImage'])
                ->whereIn('status', ['Approved', 'Ready to Publish'])
                ->latest('updated_at')
                ->take(5)
                ->get()
                ->map(function ($art) {
                    return [
                        'id' => $art->id,
                        'title' => $art->title,
                        'author' => $art->user->full_name ?? 'Author',
                        'author_avatar' => $art->user->profile_picture_url ?? '/images/member-placeholder.svg',
                        'category' => $art->category->name ?? 'General',
                        'status' => 'Ready to Publish',
                        'date' => $art->updated_at ? $art->updated_at->format('M d, Y') : ($art->created_at ? $art->created_at->format('M d, Y') : date('M d, Y')),
                        'dateApproved' => $art->updated_at ? $art->updated_at->format('M d, Y') : ($art->created_at ? $art->created_at->format('M d, Y') : date('M d, Y')),
                        'content' => $art->content,
                        'excerpt' => $art->excerpt ?: Str::limit(strip_tags($art->content ?? ''), 120),
                        'coverImage' => $art->coverImage?->file_url ?? '',
                        'assignedCoverId' => $art->coverImage ? (string) $art->coverImage->id : '',
                    ];
                });
        });

        $publishedArticles = Cache::remember('mpc_dashboard_published_articles', 30, function () {
            return Article::with(['user.role', 'category'])
                ->where('status', 'Published')
                ->orderBy('views', 'desc')
                ->take(10)
                ->get()
                ->map(function ($art) {
                    return [
                        'id' => $art->id,
                        'title' => $art->title,
                        'author' => $art->user->full_name ?? 'Author',
                        'category' => $art->category->name ?? 'General',
                        'status' => 'Published',
                        'views' => $art->views ?? 0,
                        'shares' => $art->shares ?? 0,
                        'date' => $art->published_at ? $art->published_at->format('M d, Y') : ($art->created_at ? $art->created_at->format('M d, Y') : date('M d, Y')),
                        'content' => $art->content,
                    ];
                });
        });

        $recentActivities = ActivityLog::with(['user.role', 'entity'])
            ->latest()
            ->take(5)
            ->get()
            ->map->formatForTimeline();

        $topJournalists = Cache::remember('mpc_dashboard_top_journalists', 60, function () {
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
                        'article_count' => (int) $row->article_count,
                        'count' => (int) $row->article_count,
                        'avatar' => $row->user->profile_picture_url ?? '/images/member-placeholder.svg',
                    ];
                });
        });

        $approvedCovers = ArticleImage::availableForCover()
            ->with(['uploader.role'])
            ->latest()
            ->take(30)
            ->get()
            ->map(fn($item) => $this->imageService->formatForView($item))
            ->values()
            ->all();

        if (request()->wantsJson() || request()->is('api/*')) {
            return response()->json([
                'user' => (new AuthController)->formatUserData($user),
                'metrics' => $metrics,
                'pendingArticles' => $pendingArticles,
                'publishedArticles' => $publishedArticles,
                'recentActivities' => $recentActivities,
                'topJournalists' => $topJournalists,
                'approvedCovers' => $approvedCovers,
            ]);
        }

        return view('mpc.dashboard', compact('metrics', 'pendingArticles', 'publishedArticles', 'recentActivities', 'topJournalists', 'approvedCovers'));
    }

    public function publishArticle()
    {
        $articles = Article::with(['user.role', 'category', 'coverImage.uploader'])
            ->whereIn('status', ['Approved', 'Ready to Publish', 'Published', 'Unpublished'])
            ->latest('updated_at')
            ->get()
            ->map(function ($article) {
                $rawStatus = strtolower(trim($article->status));
                $statusSlug = match ($rawStatus) {
                    'published' => 'published',
                    'unpublished' => 'unpublished',
                    default => 'ready', // 'approved', 'ready to publish', etc.
                };

                $catName = $article->category->name ?? 'General';
                $catSlug = strtolower(trim($catName));
                if (str_contains($catSlug, 'news')) {
                    $catSlug = 'news';
                } elseif (str_contains($catSlug, 'opinion')) {
                    $catSlug = 'opinion';
                } elseif (str_contains($catSlug, 'feature')) {
                    $catSlug = 'features';
                } elseif (str_contains($catSlug, 'sci') || str_contains($catSlug, 'tech')) {
                    $catSlug = 'scitech';
                } elseif (str_contains($catSlug, 'liter')) {
                    $catSlug = 'literature';
                } else {
                    $catSlug = 'news';
                }

                $authorName = $article->user->full_name ?? 'Staff Writer';
                $endorserName = $this->resolveEndorserName($article);

                return [
                    'id' => $article->id,
                    'title' => $article->title,
                    'category' => $catName,
                    'categorySlug' => $catSlug,
                    'author' => $authorName,
                    'sectionHead' => $endorserName,
                    'eicApprover' => 'Approved by Editor-in-Chief',
                    'readTime' => max(1, (int) ceil(str_word_count(strip_tags($article->content ?? '')) / 200)) . ' min read',
                    'dateApproved' => $article->updated_at ? $article->updated_at->format('M d, Y') : ($article->created_at ? $article->created_at->format('M d, Y') : date('M d, Y')),
                    'rawDate' => $article->updated_at ? $article->updated_at->toIso8601String() : ($article->created_at ? $article->created_at->toIso8601String() : now()->toIso8601String()),
                    'status' => $statusSlug,
                    'db_status' => $article->status,
                    'assignedCoverId' => $article->coverImage ? (string) $article->coverImage->id : '',
                    'coverImage' => $article->coverImage?->file_url ?? '',
                    'image' => $article->coverImage?->file_url ?? '',
                    'coverArtist' => $article->coverImage?->artist_name ?? '',
                    'coverType' => $article->coverImage?->image_type ?? '',
                    'excerpt' => $article->excerpt ?: Str::limit(strip_tags($article->content ?? ''), 120),
                    'body' => $article->content ? (preg_match('/<(?:p|div|h[1-6]|blockquote|ul|ol|li|strong|b|em|i|u|br)\b/i', $article->content) ? $article->content : collect(preg_split('/\n\n+/', trim($article->content)))->map(fn($p) => '<p>' . htmlspecialchars(trim($p)) . '</p>')->implode('')) : '<p>Manuscript content approved.</p>',
                    'publishedAt' => $article->published_at ? $article->published_at->format('M d, Y') : null,
                ];
            });

        $readyCount = $articles->where('status', 'ready')->count();
        $publishedCount = $articles->where('status', 'published')->count();
        $unpublishedCount = $articles->where('status', 'unpublished')->count();

        // Fetch genuine approved visual assets not yet assigned or published
        $approvedCoverModels = ArticleImage::availableForCover()
            ->with(['uploader.role', 'headApprover.role', 'eicApprover.role', 'article'])
            ->latest()
            ->get();
        $coversCount = $approvedCoverModels->count();
        $approvedCovers = $approvedCoverModels->map(fn($item) => $this->imageService->formatForView($item))->values()->all();

        // Also alias $approvedArticles for full view compatibility
        $approvedArticles = $articles;

        if (request()->wantsJson() || request()->is('api/*')) {
            return response()->json([
                'articles' => $articles,
                'approvedArticles' => $approvedArticles,
                'approvedCovers' => $approvedCovers,
                'readyCount' => $readyCount,
                'publishedCount' => $publishedCount,
                'unpublishedCount' => $unpublishedCount,
                'coversCount' => $coversCount,
            ]);
        }

        return view('mpc.publish-article', compact(
            'articles',
            'approvedArticles',
            'approvedCovers',
            'readyCount',
            'publishedCount',
            'unpublishedCount',
            'coversCount'
        ));
    }

    public function mediaDesk()
    {
        $pending = ArticleImage::whereIn('status', ['pending_head', 'pending', 'Pending with Head', 'Pending Review'])
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

        return view('mpc.media-desk', compact('coverList'));
    }

    public function endorseMedia(int|string $id, Request $request)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $image = ArticleImage::findOrFail($id);

        $this->imageService->endorseByHead($image, $user);
        ActivityLog::logAction($user->id, 'endorsed visual asset to EIC');
        app(\App\Services\WorkflowNotificationService::class)->notifyMediaEndorsed($image, $user);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Visual asset endorsed to Editor-in-Chief successfully.',
                'media' => $this->imageService->formatForView($image),
            ]);
        }

        return redirect()->back()->with('success', 'Visual asset endorsed to Editor-in-Chief.');
    }

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
        app(\App\Services\WorkflowNotificationService::class)->notifyMediaRevision($image, $user, (string) $request->remarks);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Visual asset returned for revision.',
                'media' => $this->imageService->formatForView($image),
            ]);
        }

        return redirect()->back()->with('success', 'Visual asset returned for revision.');
    }

    /**
     * Resubmit Media asset returned for revision
     */
    public function resubmitMedia(int|string $id, Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'media_file' => 'nullable|image|mimes:svg,png,jpg,jpeg,webp|max:10240',
            'image' => 'nullable|string',
            'title' => 'nullable|string|max:255',
        ]);

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $image = ArticleImage::findOrFail($id);

        if ($image->uploader_id !== $user->id && !str_contains(strtolower($user->role?->role_name ?? ''), 'head')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized to resubmit this media.'], 403);
        }

        $lowerStatus = strtolower(trim((string)$image->status));
        $isRevision = in_array($lowerStatus, ['revision', 'for revision', 'returned for revision', 'returned'])
            || stripos($lowerStatus, 'revision') !== false;

        if (!$isRevision) {
            return response()->json(['success' => false, 'message' => 'This media asset is not currently returned for revision.'], 422);
        }

        $fileOrData = null;
        if ($request->hasFile('media_file')) {
            $fileOrData = $request->file('media_file');
        } elseif ($request->filled('image')) {
            $fileOrData = $request->input('image');
        }

        $this->imageService->resubmitImage($image, $user, $fileOrData, $request->input('title'));
        ActivityLog::logAction($user->id, 'submitted media');
        app(\App\Services\WorkflowNotificationService::class)->notifyMediaSubmitted($image, $user);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Media asset resubmitted successfully to Editor-in-Chief!',
                'media' => $this->imageService->formatForView($image),
            ]);
        }

        return redirect()->route('mpc.manage-media')->with('success', 'Media asset resubmitted successfully!');
    }

    public function submitMedia()
    {
        return view('mpc.submit-media');
    }

    public function storeMedia(Request $request)
    {
        $request->validate([
            'media_file' => 'nullable|image|mimes:svg,png,jpg,jpeg,webp|max:10240',
            'image' => 'nullable|string',
            'title' => 'nullable|string|max:255',
            'caption' => 'nullable|string',
            'type' => 'nullable|string|in:photo,graphic',
        ]);

        $fileOrData = null;
        if ($request->hasFile('media_file')) {
            $fileOrData = $request->file('media_file');
        } elseif ($request->filled('image')) {
            $fileOrData = $request->input('image');
        }

        if ($fileOrData) {
            /** @var \App\Models\User $user */
            $user = Auth::user();
            $media = $this->imageService->storeImage(
                $user,
                $fileOrData,
                $request->input('type') ?: 'photo',
                $request->input('title')
            );
            ActivityLog::logAction($user->id, 'submitted media');
            app(\App\Services\WorkflowNotificationService::class)->notifyMediaSubmitted($media, $user);
        }

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => true,
                'message' => 'Media submitted successfully.',
            ]);
        }

        return redirect()->route('mpc.manage-media')->with('success', 'Media submitted successfully.');
    }

    public function manageMedia()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $query = ArticleImage::whereIn('image_type', ['photo', 'graphic'])
            ->with(['uploader.role', 'headApprover.role', 'eicApprover.role', 'article']);

        // Manage My Media is strictly scoped to the authenticated owner's submissions
        if ($user) {
            $query->where('uploader_id', $user->id);
        }

        $mediaItems = $query->latest()
            ->get()
            ->map(fn($item) => $this->imageService->formatForView($item))
            ->values()
            ->all();

        if (request()->wantsJson() || request()->is('api/*')) {
            return response()->json(['mediaItems' => $mediaItems]);
        }

        return view('mpc.manage-media', compact('mediaItems'));
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
                    'author' => $user->full_name ?? ($article->user->full_name ?? 'M&PC Officer'),
                ];
            });

        if (request()->wantsJson() || request()->is('api/*')) {
            return response()->json($articles);
        }

        return view('mpc.manage-my-articles', compact('articles'));
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
        $draftId = $request->input('draft_id') ?? $request->input('draftId') ?? $request->input('id');
        if ($draftId) {
            $editingDraft = Article::with(['category', 'user.role'])
                ->where('user_id', Auth::id())
                ->whereIn('status', ['Draft', 'For Revision'])
                ->find($draftId);
        }

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'categories' => $categories,
                'drafts' => $drafts,
                'editingDraft' => $editingDraft,
            ]);
        }

        return view('mpc.submit-article', compact('categories', 'drafts', 'editingDraft'));
    }

    public function storeArticle(Request $request)
    {
        $rules = [
            'title' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'status' => 'required|string',
            'article_id' => 'nullable|integer'
        ];

        $isDraft = strtolower(trim((string) $request->input('status'))) === 'draft'
            || strtolower(trim((string) $request->input('action'))) === 'draft';

        if ($isDraft) {
            $rules['content'] = 'nullable|string';
        } else {
            $rules['content'] = 'required|string';
        }

        $validated = $request->validate($rules);

        $status = $isDraft ? 'Draft' : 'Pending Review';

        if (!empty($validated['article_id'])) {
            $article = Article::where('id', $validated['article_id'])
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $isRevision = in_array(strtolower(trim((string)$article->status)), [
                'for revision', 'for writer revision', 'for head revision',
                'for section head revision', 'returned for revision', 'revision',
            ]) || stripos((string)$article->status, 'revision') !== false;

            if ($isRevision && $status !== 'Draft') {
                if (!Article::isBodyModified($article->content, $validated['content'] ?? '')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You cannot submit an article returned for revision without editing the article body. Please revise the manuscript before submitting.',
                    ], 422);
                }
            }

            $article->update([
                'title' => $validated['title'],
                'category_id' => $validated['category_id'],
                'content' => $validated['content'] ?? '',
                'excerpt' => substr(strip_tags($validated['content'] ?? ''), 0, 150),
                'status' => $status,
                'remarks' => null,
            ]);

            if ($status === 'Pending Review') {
                ActivityLog::logAction(Auth::id(), 'submitted', $article);
                /** @var \App\Models\User|null $actor */
                $actor = Auth::user();
                if ($actor) {
                    app(\App\Services\WorkflowNotificationService::class)->notifyArticleSubmitted($article, $actor);
                }
            }
        } else {
            $article = Article::create([
                'user_id' => Auth::id(),
                'title' => $validated['title'],
                'category_id' => $validated['category_id'],
                'content' => $validated['content'] ?? '',
                'excerpt' => substr(strip_tags($validated['content'] ?? ''), 0, 150),
                'status' => $status,
                'views' => 0,
                'shares' => 0,
            ]);

            if ($status === 'Pending Review') {
                ActivityLog::logAction(Auth::id(), 'submitted', $article);
                /** @var \App\Models\User|null $actor */
                $actor = Auth::user();
                if ($actor) {
                    app(\App\Services\WorkflowNotificationService::class)->notifyArticleSubmitted($article, $actor);
                }
            }
        }

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => true,
                'message' => 'Article saved successfully.',
                'article' => $article,
            ]);
        }

        return redirect()->route('mpc.manage-my-articles')->with('success', 'Article saved successfully.');
    }

    public function destroyDraft($id)
    {
        $draft = Article::where('id', $id)
            ->where('user_id', Auth::id())
            ->whereIn('status', ['Draft', 'For Revision'])
            ->firstOrFail();

        $draft->delete();

        if (request()->wantsJson() || request()->is('api/*')) {
            return response()->json([
                'success' => true,
                'message' => 'Draft deleted successfully.',
            ]);
        }

        return redirect()->back()->with('success', 'Draft deleted successfully.');
    }

    public function activityLogs()
    {
        $activities = ActivityLog::with(['user.role', 'entity'])
            ->latest()
            ->take(100)
            ->get()
            ->map->formatForTimeline();

        if (request()->wantsJson() || request()->is('api/*')) {
            return response()->json(['activities' => $activities]);
        }

        return view('mpc.activity-logs', compact('activities'));
    }

    public function transparency(Request $request)
    {
        if ($request->wantsJson() || $request->ajax() || $request->is('api/*')) {
            return response()->json(['status' => 'ok']);
        }

        return response()->json(['status' => 'ok']);
    }

    public function assignCover(Request $request, $id)
    {
        $request->validate([
            'cover_id' => 'required|exists:article_images,id',
        ]);

        $article = Article::findOrFail($id);
        $image = $this->imageService->assignCoverToArticle($article, $request->cover_id);

        ActivityLog::logAction(Auth::id(), 'assigned cover to', $article);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Cover attached successfully.',
                'cover' => $this->imageService->formatForView($image),
            ]);
        }

        return redirect()->back()->with('success', 'Cover attached successfully.');
    }

    public function publishArticleRelease(Request $request, $id)
    {
        $article = Article::findOrFail($id);

        if ($request->filled('cover_id')) {
            $this->imageService->assignCoverToArticle($article, $request->cover_id);
        }

        $article->update([
            'status' => 'Published',
            'published_at' => now(),
        ]);

        ActivityLog::logAction(Auth::id(), 'published', $article);
        /** @var \App\Models\User|null $publisher */
        $publisher = Auth::user();
        if ($publisher) {
            app(\App\Services\WorkflowNotificationService::class)->notifyArticlePublished($article, $publisher);
        }

        Cache::forget('mpc_dashboard_stats');
        Cache::forget('mpc_dashboard_pending_articles');
        Cache::forget('mpc_dashboard_published_articles');
        Cache::forget('eic_dashboard_stats');
        Cache::forget('writer_dashboard_stats_' . $article->user_id);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => 'Article published successfully.']);
        }

        return redirect()->back()->with('success', 'Article published successfully.');
    }

    public function unpublishArticleRelease(Request $request, $id)
    {
        $article = Article::findOrFail($id);
        $article->update([
            'status' => 'Unpublished',
        ]);

        ActivityLog::logAction(Auth::id(), 'unpublished', $article);

        Cache::forget('mpc_dashboard_stats');
        Cache::forget('mpc_dashboard_pending_articles');
        Cache::forget('mpc_dashboard_published_articles');
        Cache::forget('eic_dashboard_stats');
        Cache::forget('writer_dashboard_stats_' . $article->user_id);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => 'Article unpublished.']);
        }

        return redirect()->back()->with('success', 'Article unpublished.');
    }

    public function returnArticleForRevision(Request $request, $id)
    {
        $request->validate([
            'remarks' => 'required|string',
        ]);

        $article = Article::findOrFail($id);
        $article->update([
            'status' => 'Pending EIC Review',
            'remarks' => $request->remarks,
        ]);

        ActivityLog::logAction(Auth::id(), 'returned for revision', $article);
        /** @var \App\Models\User|null $reviewer */
        $reviewer = Auth::user();
        if ($reviewer) {
            app(\App\Services\WorkflowNotificationService::class)->notifyArticleRevision($article, $reviewer, (string) $request->remarks, 'Pending EIC Review');
        }

        Cache::forget('mpc_dashboard_stats');
        Cache::forget('mpc_dashboard_pending_articles');
        Cache::forget('mpc_dashboard_published_articles');
        Cache::forget('eic_dashboard_stats');
        Cache::forget('eic_review_articles_newest');
        Cache::forget('eic_review_articles_oldest');

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => 'Article returned to Editor-in-Chief for revision.']);
        }

        return redirect()->back()->with('success', 'Article returned to Editor-in-Chief for revision.');
    }

    public function profileSettings()
    {
        if (request()->wantsJson() || request()->is('api/*')) {
            return response()->json(['user' => (new AuthController)->formatUserData(Auth::user())]);
        }

        return view('mpc.profile-settings');
    }

    protected function getCategoryHeadsMap(): array
    {
        return Cache::remember('category_section_heads_map_v5', 3600, function () {
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
                }
            } catch (\Throwable $e) {}

            return $map;
        });
    }

    protected function extractLastName(?string $fullName): string
    {
        if (empty($fullName)) return '';
        $parts = preg_split('/\s+/', trim($fullName));
        if (count($parts) === 1) return $parts[0];

        $last = array_pop($parts);
        $prev = end($parts);
        if ($prev && in_array(strtolower($prev), ['de', 'dela', 'del', 'delos', 'san'])) {
            return $prev . ' ' . $last;
        }

        return $last;
    }

    protected function resolveEndorserName($article): string
    {
        $catName = $article->category->name ?? '';
        if (!empty($catName) && stripos($catName, 'opinion') !== false) {
            return 'Direct Submission (Opinion)';
        }

        $headsMap = $this->getCategoryHeadsMap();
        if (!empty($catName)) {
            if (isset($headsMap[$catName])) {
                return 'Approved by ' . $headsMap[$catName];
            }
            foreach ($headsMap as $catKey => $headVal) {
                if (stripos($catName, $catKey) !== false || stripos($catKey, $catName) !== false) {
                    return 'Approved by ' . $headVal;
                }
            }
        }

        if (!empty($article->endorser)) {
            return 'Approved by ' . $article->endorser;
        }

        return 'Approved by Section Head';
    }
}

