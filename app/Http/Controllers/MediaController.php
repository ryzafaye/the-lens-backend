<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Category;
use App\Models\User;
use App\Services\ArticleImageService;
use App\Traits\LogsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class MediaController extends Controller
{
    use LogsActivity;

    public function __construct(
        protected ArticleImageService $imageService
    ) {}

    /**
     * Media & Communication Dashboard
     */
    public function dashboard(): View|JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $user?->loadMissing('role');
        $roleName = $user->role?->role_name ?? '';
        $normRole = strtolower(trim($roleName));

        $isSectionHead = in_array($roleName, [
            'Photojournalist Head',
            'Associate Media and Public Communications Head',
            'Media and Public Communications Head',
            'M&PC Head',
            'Associate M&PC Head',
        ], true) || str_contains($normRole, 'head') || str_contains($normRole, 'chief');

        $mediaScope = fn($q) => $q->where(function ($sub) {
            $sub->whereIn('image_type', ['photo', 'graphic', 'photos'])
                ->orWhereNull('image_type');
        });

        if ($isSectionHead) {
            $pendingModels = ArticleImage::where($mediaScope)
                ->pendingHead()
                ->with(['uploader.role'])
                ->latest()
                ->get();

            $pendingCount = $pendingModels->count();
            $publishedCount = ArticleImage::where($mediaScope)->whereIn('status', ['published', 'Published'])->count();
            $libraryCount = ArticleImage::where($mediaScope)->approved()->count();
            $revisionCount = ArticleImage::where($mediaScope)
                ->whereIn('status', ['revision', 'for revision', 'returned for revision', 'Returned for Revision', 'For Revision'])
                ->count();

            if ($pendingCount > 0) {
                $mediaItems = $pendingModels->take(5)->map(fn($item) => $this->imageService->formatForView($item))->values()->all();
            } else {
                $userMedia = ArticleImage::where($mediaScope)
                    ->where('uploader_id', $user->id)
                    ->with(['uploader.role', 'headApprover.role', 'article'])
                    ->latest()
                    ->take(10)
                    ->get();
                $mediaItems = $userMedia->map(fn($item) => $this->imageService->formatForView($item))->values()->all();
            }
        } else {
            $userMedia = ArticleImage::where($mediaScope)
                ->where('uploader_id', $user->id)
                ->with(['uploader.role', 'headApprover.role', 'article'])
                ->latest()
                ->take(10)
                ->get();

            $pendingCount = ArticleImage::where($mediaScope)
                ->where('uploader_id', $user->id)
                ->pendingHead()
                ->count();
            $publishedCount = ArticleImage::where($mediaScope)
                ->where('uploader_id', $user->id)
                ->whereIn('status', ['published', 'Published'])
                ->count();
            $libraryCount = ArticleImage::where($mediaScope)->approved()->count();
            $revisionCount = ArticleImage::where($mediaScope)
                ->where('uploader_id', $user->id)
                ->whereIn('status', ['revision', 'for revision', 'returned for revision', 'Returned for Revision', 'For Revision'])
                ->count();

            $mediaItems = $userMedia->map(fn($item) => $this->imageService->formatForView($item))->values()->all();
        }

        $totalPublishedArticles = Article::whereIn('status', ['published', 'Published'])->count();
        $userPublishedArticles = Article::where('user_id', $user?->id)->whereIn('status', ['published', 'Published'])->count();

        $metrics = [
            'published' => $publishedCount,
            'pending' => $pendingCount,
            'revision' => $revisionCount,
            'published_articles' => $isSectionHead ? $totalPublishedArticles : $userPublishedArticles,
            'library' => $libraryCount,
        ];

        if (request()->wantsJson() || request()->ajax()) {
            return response()->json([
                'success' => true,
                'metrics' => $metrics,
                'mediaItems' => $mediaItems,
                'isSectionHead' => $isSectionHead,
            ]);
        }

        return view('media.dashboard', compact('metrics', 'mediaItems', 'isSectionHead'));
    }

    /**
     * Submit Article View
     */
    public function submitArticle(Request $request): View|JsonResponse
    {
        $categories = Cache::remember('all_categories', 86400, fn() => Category::all());
        $drafts = Article::with(['category', 'user.role'])
            ->where('user_id', Auth::id())
            ->where('status', 'Draft')
            ->latest()
            ->get();

        $editingDraft = null;
        if ($request->has('draft_id')) {
            $editingDraft = Article::with(['category', 'user.role'])
                ->where('user_id', Auth::id())
                ->whereIn('status', ['Draft', 'For Revision'])
                ->find($request->draft_id);
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'categories' => $categories,
                'drafts' => $drafts,
                'editingDraft' => $editingDraft,
            ]);
        }

        return view('media.submit-article', compact('categories', 'drafts', 'editingDraft'));
    }

    /**
     * Store Article Submission or Draft
     */
    public function storeArticle(Request $request): RedirectResponse|JsonResponse
    {
        $rules = [
            'title' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'status' => 'required|string',
            'article_id' => 'nullable|integer',
        ];

        if ($request->status === 'Pending Review') {
            $rules['excerpt'] = 'required|string|max:255';
            $rules['content'] = 'required|string';
        } else {
            $rules['excerpt'] = 'nullable|string|max:255';
            $rules['content'] = 'nullable|string';
        }

        $request->validate($rules);
        $cleanContent = $this->sanitizeArticleContent($request->content);

        try {
            if ($request->filled('article_id')) {
                $article = Article::where('user_id', Auth::id())->find($request->article_id);
                if ($article) {
                    $isRevision = in_array(strtolower(trim((string)$article->status)), [
                        'for revision', 'for writer revision', 'for head revision',
                        'for section head revision', 'returned for revision', 'revision',
                    ]) || stripos((string)$article->status, 'revision') !== false;

                    if ($isRevision && $request->status !== 'Draft') {
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
                        'status' => $request->status,
                        'remarks' => null,
                    ]);

                    if ($request->status === 'Pending Review') {
                        ActivityLog::logAction(Auth::id(), 'submitted', $article);
                        /** @var \App\Models\User|null $actor */
                        $actor = Auth::user();
                        if ($actor) {
                            app(\App\Services\WorkflowNotificationService::class)->notifyArticleSubmitted($article, $actor);
                        }
                    }

                    $this->clearCaches((int) $request->category_id);

                    if ($request->wantsJson() || $request->ajax()) {
                        return response()->json([
                            'success' => true,
                            'message' => $request->status === 'Draft' ? 'Draft saved successfully.' : 'Article submitted successfully.',
                            'article' => $article,
                        ]);
                    }

                    if ($request->status === 'Draft') {
                        return redirect()->route('media.submit-article', ['tab' => 'drafts'])
                            ->with('success', 'Draft saved successfully.');
                    }
                    return redirect()->route('media.dashboard')
                        ->with('success', 'Article submitted successfully.');
                }
            }

            $article = Article::create([
                'user_id' => Auth::id(),
                'category_id' => $request->category_id,
                'title' => $request->title,
                'excerpt' => $request->excerpt,
                'content' => $cleanContent,
                'status' => $request->status,
                'views' => 0,
                'shares' => 0,
            ]);

            if ($request->status === 'Pending Review') {
                ActivityLog::logAction(Auth::id(), 'submitted', $article);
                /** @var \App\Models\User|null $actor */
                $actor = Auth::user();
                if ($actor) {
                    app(\App\Services\WorkflowNotificationService::class)->notifyArticleSubmitted($article, $actor);
                }
            }

            $this->clearCaches((int) $request->category_id);

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $request->status === 'Draft' ? 'Draft saved successfully.' : 'Article submitted successfully to the editorial queue.',
                    'article' => $article,
                ]);
            }

            if ($request->status === 'Draft') {
                return redirect()->route('media.submit-article', ['tab' => 'drafts'])
                    ->with('success', 'Draft saved successfully.');
            }

            return redirect()->route('media.dashboard')
                ->with('success', 'Article submitted successfully to the editorial queue.');

        } catch (\Exception $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => 'Something went wrong: ' . $e->getMessage()], 500);
            }
            return back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    /**
     * Manage My Articles View
     */
    public function manageArticles(): View|JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $user?->loadMissing('role');

        $articles = Article::with(['category', 'user.role', 'coverImage'])
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
                    'author' => $user->full_name ?? ($article->user->full_name ?? 'Media Team'),
                ];
            });

        if (request()->wantsJson() || request()->ajax()) {
            return response()->json([
                'success' => true,
                'articles' => $articles,
            ]);
        }

        return view('media.manage-articles', compact('articles'));
    }

    /**
     * Submit Media View
     */
    public function submitMedia(): View
    {
        return view('media.submit-media');
    }

    /**
     * Store Photo / Graphic Submission (File or Base64 upload)
     */
    public function storeMedia(Request $request): JsonResponse|RedirectResponse
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

        if (!$fileOrData) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => 'No image file or photo payload received.'], 422);
            }
            return back()->with('error', 'Please select a photo or graphic to upload.');
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $imageType = $request->input('type') ?: 'photo';

        $media = $this->imageService->storeImage(
            $user,
            $fileOrData,
            $imageType,
            $request->input('title')
        );

        ActivityLog::logAction($user->id, "submitted {$imageType}");
        app(\App\Services\WorkflowNotificationService::class)->notifyMediaSubmitted($media, $user);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Photo submitted successfully!',
                'media' => $this->imageService->formatForView($media),
            ]);
        }

        return redirect()->route('media.manage-media')
            ->with('success', 'Photo submitted successfully to the Media Desk!');
    }

    /**
     * Manage Media View
     */
    public function manageMedia(): View|JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $user?->loadMissing('role');
        $roleName = $user->role?->role_name ?? '';

        $isSectionHead = in_array($roleName, [
            'Photojournalist Head',
            'Associate Media and Public Communications Head',
            'Media and Public Communications Head',
            'M&PC Head',
            'Associate M&PC Head',
        ], true);

        $query = ArticleImage::whereIn('image_type', ['photo', 'graphic'])
            ->with(['uploader.role', 'headApprover.role', 'eicApprover.role', 'article']);

        // Always scope Manage My Media to the authenticated owner's submissions
        if ($user) {
            $query->where('uploader_id', $user->id);
        }

        $mediaItems = $query->latest()
            ->get()
            ->map(fn($item) => $this->imageService->formatForView($item))
            ->values()
            ->all();

        if (request()->wantsJson() || request()->ajax()) {
            return response()->json([
                'success' => true,
                'mediaItems' => $mediaItems,
                'isSectionHead' => $isSectionHead,
            ]);
        }

        return view('media.manage-media', compact('mediaItems', 'isSectionHead'));
    }

    /**
     * Endorse Media to Editor-in-Chief
     */
    public function endorseMedia(int|string $id, Request $request): JsonResponse|RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $image = ArticleImage::whereIn('image_type', ['photo', 'graphic'])->findOrFail($id);

        $this->imageService->endorseByHead($image, $user);
        ActivityLog::logAction($user->id, 'endorsed photo to EIC');
        app(\App\Services\WorkflowNotificationService::class)->notifyMediaEndorsed($image, $user);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Photo endorsed to Editor-in-Chief successfully.',
                'media' => $this->imageService->formatForView($image),
            ]);
        }

        return redirect()->back()->with('success', 'Photo endorsed to Editor-in-Chief.');
    }

    /**
     * Return Media to Contributor for Revision
     */
    public function returnMediaForRevision(int|string $id, Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'remarks' => 'required|string',
        ]);

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $image = ArticleImage::whereIn('image_type', ['photo', 'graphic'])->findOrFail($id);

        $this->imageService->returnForRevision($image, $user, (string) $request->remarks);
        ActivityLog::logAction($user->id, 'returned photo for revision');
        app(\App\Services\WorkflowNotificationService::class)->notifyMediaRevision($image, $user, (string) $request->remarks);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Photo returned for revision.',
                'media' => $this->imageService->formatForView($image),
            ]);
        }

        return redirect()->back()->with('success', 'Photo returned for revision.');
    }

    /**
     * Resubmit Photo / Media returned for revision
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
        $image = ArticleImage::whereIn('image_type', ['photo', 'graphic'])->findOrFail($id);

        if ($image->uploader_id !== $user->id && !str_contains(strtolower($user->role?->role_name ?? ''), 'head')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized to resubmit this photo.'], 403);
        }

        $lowerStatus = strtolower(trim((string)$image->status));
        $isRevision = in_array($lowerStatus, ['revision', 'for revision', 'returned for revision', 'returned'])
            || stripos($lowerStatus, 'revision') !== false;

        if (!$isRevision) {
            return response()->json(['success' => false, 'message' => 'This photo is not currently returned for revision.'], 422);
        }

        $fileOrData = null;
        if ($request->hasFile('media_file')) {
            $fileOrData = $request->file('media_file');
        } elseif ($request->filled('image')) {
            $fileOrData = $request->input('image');
        }

        $this->imageService->resubmitImage($image, $user, $fileOrData, $request->input('title'));
        ActivityLog::logAction($user->id, 'submitted media photo');
        app(\App\Services\WorkflowNotificationService::class)->notifyMediaSubmitted($image, $user);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Photo resubmitted successfully!',
                'media' => $this->imageService->formatForView($image),
            ]);
        }

        return redirect()->route('media.manage-media')->with('success', 'Photo resubmitted successfully!');
    }

    /**
     * Profile Settings View
     */
    public function profileSettings(): View|JsonResponse
    {
        if (request()->wantsJson() || request()->ajax()) {
            return response()->json([
                'success' => true,
                'user' => (new AuthController)->formatUserData(Auth::user()),
            ]);
        }

        return view('media.profile-settings');
    }

    /**
     * Delete Draft
     */
    public function destroyDraft(int|string $id): RedirectResponse|JsonResponse
    {
        $article = Article::where('user_id', Auth::id())
            ->where('status', 'Draft')
            ->findOrFail($id);

        $article->delete();
        $this->clearCaches((int) $article->category_id);

        if (request()->wantsJson() || request()->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Draft deleted successfully.',
            ]);
        }

        return redirect()->route('media.submit-article', ['tab' => 'drafts'])
            ->with('success', 'Draft deleted successfully.');
    }

    /**
     * Clear editorial caches
     */
    private function clearCaches(?int $categoryId = null): void
    {
        Cache::forget('eic_dashboard_stats');
        Cache::forget('eic_review_articles_newest');
        Cache::forget('eic_review_articles_oldest');
        if ($categoryId) {
            Cache::forget('exec_review_articles_' . $categoryId . '_newest');
            Cache::forget('exec_review_articles_' . $categoryId . '_oldest');
        }
        Cache::forget('exec_review_articles_all_newest');
        Cache::forget('exec_review_articles_all_oldest');
    }

    /**
     * Basic sanitization helper
     */
    private function sanitizeArticleContent(?string $content): string
    {
        return Article::cleanArticleBodyText($content);
    }
}
