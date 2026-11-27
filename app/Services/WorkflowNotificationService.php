<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Controllers\AuthController;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\User;
use App\Notifications\EditorialWorkflowNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class WorkflowNotificationService
{
    /**
     * Notify reviewer(s) when an article is submitted or resubmitted for review.
     */
    public function notifyArticleSubmitted(Article $article, User $actor): void
    {
        $articleId = $article->id;
        $actorId = $actor->id;
        $status = (string) $article->status;

        $this->dispatchSafely('article.submitted', function () use ($articleId, $actorId, $status) {
            $art = Article::with(['category', 'user.role'])->find($articleId);
            $actorUser = User::with('role')->find($actorId);
            if (!$art || !$actorUser) {
                return;
            }

            $recipients = $this->resolveArticleReviewerRecipients($art, $status, $actorId);
            if ($recipients->isEmpty()) {
                return;
            }

            $authorName = $actorUser->full_name ?: ($art->user?->full_name ?? 'Editorial Member');
            $categoryName = $art->category?->name ?? 'General';

            foreach ($recipients as $recipient) {
                $url = $this->buildUrlForUser($recipient, 'review-articles');
                $recipient->notify(new EditorialWorkflowNotification(
                    eventType: 'article_submitted',
                    subjectLine: "Article Submitted for Review: {$art->title}",
                    headline: 'Article Submitted for Review',
                    summary: "{$authorName} submitted an article under {$categoryName} that is now awaiting your review.",
                    itemLabel: 'Article Title',
                    itemTitle: $art->title,
                    statusLabel: $status,
                    actorName: $authorName,
                    actionText: 'Review Article',
                    actionUrl: $url,
                ));
            }
        });
    }

    /**
     * Notify the author (and section head / EIC when applicable) when an article is returned for revision.
     */
    public function notifyArticleRevision(Article $article, User $reviewer, string $remarks, string $targetStatus): void
    {
        $articleId = $article->id;
        $reviewerId = $reviewer->id;

        $this->dispatchSafely('article.revision', function () use ($articleId, $reviewerId, $remarks, $targetStatus) {
            $art = Article::with(['category', 'user.role'])->find($articleId);
            $reviewerUser = User::with('role')->find($reviewerId);
            if (!$art || !$reviewerUser) {
                return;
            }

            $reviewerName = $this->formatActorWithRole($reviewerUser, 'Editorial Reviewer');
            $targets = collect();

            if ($art->user) {
                $targets->push($art->user);
            }

            if ($targetStatus === 'For Head Revision') {
                $targets = $targets->merge($this->getSectionHeadsForCategory($art->category?->name, $reviewerId));
            } elseif ($targetStatus === 'Pending EIC Review') {
                $targets = $targets->merge($this->getUsersByRoleKeywords(['editor-in-chief', 'associate editor-in-chief'], $reviewerId));
            }

            $recipients = $this->filterEligibleRecipients($targets, $reviewerId);
            foreach ($recipients as $recipient) {
                $context = ((int) $recipient->id === (int) $art->user_id) ? 'my-articles' : 'review-articles';
                $url = $this->buildUrlForUser($recipient, $context);
                $recipient->notify(new EditorialWorkflowNotification(
                    eventType: 'article_revision',
                    subjectLine: "Revision Requested: {$art->title}",
                    headline: 'Article Returned for Revision',
                    summary: "{$reviewerName} requested revisions on \"{$art->title}\". Please review the editor remarks below and update the submission.",
                    itemLabel: 'Article Title',
                    itemTitle: $art->title,
                    statusLabel: $targetStatus,
                    actorName: $reviewerName,
                    remarks: $remarks,
                    actionText: 'View Article & Remarks',
                    actionUrl: $url,
                ));
            }
        });
    }

    /**
     * Notify the author and next workflow stage when an article is approved.
     */
    public function notifyArticleApproved(Article $article, User $reviewer, string $newStatus): void
    {
        $articleId = $article->id;
        $reviewerId = $reviewer->id;

        $this->dispatchSafely('article.approved', function () use ($articleId, $reviewerId, $newStatus) {
            $art = Article::with(['category', 'user.role'])->find($articleId);
            $reviewerUser = User::with('role')->find($reviewerId);
            if (!$art || !$reviewerUser) {
                return;
            }

            $reviewerName = $reviewerUser->full_name ?: 'Editorial Reviewer';

            // 1. Notify the author
            if ($art->user) {
                foreach ($this->filterEligibleRecipients(collect([$art->user]), $reviewerId) as $author) {
                    $url = $this->buildUrlForUser($author, 'my-articles');
                    $author->notify(new EditorialWorkflowNotification(
                        eventType: 'article_approved',
                        subjectLine: "Article Approved ({$newStatus}): {$art->title}",
                        headline: 'Article Approved',
                        summary: $newStatus === 'Approved'
                            ? "Your article \"{$art->title}\" has been approved by {$reviewerName} and forwarded to M&PC for publication."
                            : "Your article \"{$art->title}\" passed Section Head review by {$reviewerName} and has been forwarded for EIC review.",
                        itemLabel: 'Article Title',
                        itemTitle: $art->title,
                        statusLabel: $newStatus,
                        actorName: $reviewerName,
                        actionText: 'View My Articles',
                        actionUrl: $url,
                    ));
                }
            }

            // 2. Notify next stage editors/publishers
            if ($newStatus === 'Pending EIC Review') {
                $nextEditors = $this->resolveArticleReviewerRecipients($art, $newStatus, $reviewerId);
                foreach ($nextEditors as $editor) {
                    $url = $this->buildUrlForUser($editor, 'review-articles');
                    $editor->notify(new EditorialWorkflowNotification(
                        eventType: 'article_submitted',
                        subjectLine: "Article Ready for EIC Review: {$art->title}",
                        headline: 'Article Forwarded for Final Review',
                        summary: "{$reviewerName} approved \"{$art->title}\" and forwarded it for Editor-in-Chief / Executive review.",
                        itemLabel: 'Article Title',
                        itemTitle: $art->title,
                        statusLabel: $newStatus,
                        actorName: $reviewerName,
                        actionText: 'Review Article',
                        actionUrl: $url,
                    ));
                }
            } elseif ($newStatus === 'Approved') {
                $mpcUsers = $this->getUsersByRoleKeywords(['media and public', 'm&pc'], $reviewerId);
                foreach ($mpcUsers as $mpcUser) {
                    $url = $this->buildUrlForUser($mpcUser, 'publish-article');
                    $mpcUser->notify(new EditorialWorkflowNotification(
                        eventType: 'article_approved_for_publish',
                        subjectLine: "Article Ready to Publish: {$art->title}",
                        headline: 'Approved Article Ready for Publication',
                        summary: "{$reviewerName} approved \"{$art->title}\". It is now ready for cover assignment and publication on The Lens.",
                        itemLabel: 'Article Title',
                        itemTitle: $art->title,
                        statusLabel: 'Approved',
                        actorName: $reviewerName,
                        actionText: 'Open Publish Queue',
                        actionUrl: $url,
                    ));
                }
            }
        });
    }

    /**
     * Notify the author when an article is published live by M&PC.
     */
    public function notifyArticlePublished(Article $article, User $publisher): void
    {
        $articleId = $article->id;
        $publisherId = $publisher->id;

        $this->dispatchSafely('article.published', function () use ($articleId, $publisherId) {
            $art = Article::with(['category', 'user.role'])->find($articleId);
            $publisherUser = User::with('role')->find($publisherId);
            if (!$art || !$publisherUser || !$art->user) {
                return;
            }

            $publisherName = $publisherUser->full_name ?: 'M&PC Team';
            $publicSlug = $art->slug ?: (string) $art->id;
            $articleUrl = $this->frontendBaseUrl() . '/article/' . urlencode($publicSlug);

            foreach ($this->filterEligibleRecipients(collect([$art->user]), $publisherId) as $author) {
                $author->notify(new EditorialWorkflowNotification(
                    eventType: 'article_published',
                    subjectLine: "Article Published: {$art->title}",
                    headline: 'Your Article Is Now Live',
                    summary: "{$publisherName} published \"{$art->title}\" on The Lens Publication website.",
                    itemLabel: 'Article Title',
                    itemTitle: $art->title,
                    statusLabel: 'Published',
                    actorName: $publisherName,
                    actionText: 'Read Published Article',
                    actionUrl: $articleUrl,
                ));
            }
        });
    }

    /**
     * Notify reviewer(s) when a photo, graphic, or cartoon is submitted or resubmitted.
     */
    public function notifyMediaSubmitted(ArticleImage $image, User $actor): void
    {
        $imageId = $image->id;
        $actorId = $actor->id;

        $this->dispatchSafely('media.submitted', function () use ($imageId, $actorId) {
            $img = ArticleImage::with('uploader.role')->find($imageId);
            $actorUser = User::with('role')->find($actorId);
            if (!$img || !$actorUser) {
                return;
            }

            $isCartoon = strtolower((string) $img->image_type) === 'cartoon';
            $itemLabel = $isCartoon ? 'Editorial Cartoon' : 'Visual Media';
            $itemTitle = $img->title ?: ($isCartoon ? 'Editorial Cartoon' : 'Editorial Photo');
            $actorName = $actorUser->full_name ?: 'Contributor';

            if ($img->status === 'pending_eic') {
                $recipients = $this->getUsersByRoleKeywords(['editor-in-chief', 'associate editor-in-chief'], $actorId);
            } elseif ($isCartoon) {
                $recipients = $this->getUsersByRoleKeywords(['cartoonist head', 'chief cartoonist', 'associate cartoonist head'], $actorId);
            } else {
                $recipients = $this->getUsersByRoleKeywords(['media and public', 'm&pc', 'photojournalist head'], $actorId);
            }

            foreach ($recipients as $recipient) {
                $url = $this->buildUrlForUser($recipient, 'media-desk');
                $recipient->notify(new EditorialWorkflowNotification(
                    eventType: 'media_submitted',
                    subjectLine: "{$itemLabel} Submitted for Review: {$itemTitle}",
                    headline: "{$itemLabel} Submitted for Review",
                    summary: "{$actorName} submitted \"{$itemTitle}\" for editorial visual review.",
                    itemLabel: $itemLabel,
                    itemTitle: $itemTitle,
                    statusLabel: $img->status === 'pending_eic' ? 'Pending EIC Review' : 'Pending Desk Review',
                    actorName: $actorName,
                    actionText: 'Open Visual Desk',
                    actionUrl: $url,
                ));
            }
        });
    }

    /**
     * Notify EIC/AEIC when a visual asset or cartoon is endorsed by Desk Head.
     */
    public function notifyMediaEndorsed(ArticleImage $image, User $head): void
    {
        $imageId = $image->id;
        $headId = $head->id;

        $this->dispatchSafely('media.endorsed', function () use ($imageId, $headId) {
            $img = ArticleImage::with('uploader.role')->find($imageId);
            $headUser = User::with('role')->find($headId);
            if (!$img || !$headUser) {
                return;
            }

            $isCartoon = strtolower((string) $img->image_type) === 'cartoon';
            $itemLabel = $isCartoon ? 'Editorial Cartoon' : 'Visual Media';
            $itemTitle = $img->title ?: ($isCartoon ? 'Editorial Cartoon' : 'Editorial Photo');
            $headName = $headUser->full_name ?: 'Section Head';

            $recipients = $this->getUsersByRoleKeywords(['editor-in-chief', 'associate editor-in-chief'], $headId);
            foreach ($recipients as $recipient) {
                $url = $this->buildUrlForUser($recipient, 'media-desk');
                $recipient->notify(new EditorialWorkflowNotification(
                    eventType: 'media_endorsed',
                    subjectLine: "{$itemLabel} Endorsed for EIC Approval: {$itemTitle}",
                    headline: "{$itemLabel} Endorsed for Approval",
                    summary: "{$headName} endorsed \"{$itemTitle}\" to the Editor-in-Chief visual library queue.",
                    itemLabel: $itemLabel,
                    itemTitle: $itemTitle,
                    statusLabel: 'Pending EIC Review',
                    actorName: $headName,
                    actionText: 'Inspect in Media Desk',
                    actionUrl: $url,
                ));
            }
        });
    }

    /**
     * Notify the artist/uploader when an artwork, photo, or cartoon is returned for revision.
     */
    public function notifyMediaRevision(ArticleImage $image, User $reviewer, string $remarks): void
    {
        $imageId = $image->id;
        $reviewerId = $reviewer->id;

        $this->dispatchSafely('media.revision', function () use ($imageId, $reviewerId, $remarks) {
            $img = ArticleImage::with('uploader.role')->find($imageId);
            $reviewerUser = User::with('role')->find($reviewerId);
            if (!$img || !$reviewerUser || !$img->uploader) {
                return;
            }

            $isCartoon = strtolower((string) $img->image_type) === 'cartoon';
            $itemLabel = $isCartoon ? 'Editorial Cartoon' : 'Visual Media';
            $itemTitle = $img->title ?: ($isCartoon ? 'Editorial Cartoon' : 'Editorial Photo');
            $reviewerName = $this->formatActorWithRole($reviewerUser, 'Editorial Reviewer');

            foreach ($this->filterEligibleRecipients(collect([$img->uploader]), $reviewerId) as $creator) {
                $url = $this->buildUrlForUser($creator, 'my-media');
                $creator->notify(new EditorialWorkflowNotification(
                    eventType: 'media_revision',
                    subjectLine: "Revision Requested ({$itemLabel}): {$itemTitle}",
                    headline: "{$itemLabel} Returned for Revision",
                    summary: "{$reviewerName} returned \"{$itemTitle}\" for revision. Please check the editor remarks below.",
                    itemLabel: $itemLabel,
                    itemTitle: $itemTitle,
                    statusLabel: 'For Revision',
                    actorName: $reviewerName,
                    remarks: $remarks,
                    actionText: 'Open Submissions',
                    actionUrl: $url,
                ));
            }
        });
    }

    /**
     * Notify creator and M&PC when EIC approves an artwork/photo/cartoon.
     */
    public function notifyMediaApproved(ArticleImage $image, User $eic): void
    {
        $imageId = $image->id;
        $eicId = $eic->id;

        $this->dispatchSafely('media.approved', function () use ($imageId, $eicId) {
            $img = ArticleImage::with('uploader.role')->find($imageId);
            $eicUser = User::with('role')->find($eicId);
            if (!$img || !$eicUser) {
                return;
            }

            $isCartoon = strtolower((string) $img->image_type) === 'cartoon';
            $itemLabel = $isCartoon ? 'Editorial Cartoon' : 'Visual Media';
            $itemTitle = $img->title ?: ($isCartoon ? 'Editorial Cartoon' : 'Editorial Photo');
            $eicName = $eicUser->full_name ?: 'Editor-in-Chief';

            if ($img->uploader) {
                foreach ($this->filterEligibleRecipients(collect([$img->uploader]), $eicId) as $creator) {
                    $url = $this->buildUrlForUser($creator, 'my-media');
                    $creator->notify(new EditorialWorkflowNotification(
                        eventType: 'media_approved',
                        subjectLine: "{$itemLabel} Approved: {$itemTitle}",
                        headline: "{$itemLabel} Approved Into Library",
                        summary: "{$eicName} approved \"{$itemTitle}\" into the official publication cover library.",
                        itemLabel: $itemLabel,
                        itemTitle: $itemTitle,
                        statusLabel: 'EIC Approved',
                        actorName: $eicName,
                        actionText: 'View Submissions',
                        actionUrl: $url,
                    ));
                }
            }

            $mpcUsers = $this->getUsersByRoleKeywords(['media and public', 'm&pc'], $eicId);
            foreach ($mpcUsers as $mpcUser) {
                $url = $this->buildUrlForUser($mpcUser, 'publish-article');
                $mpcUser->notify(new EditorialWorkflowNotification(
                    eventType: 'media_ready_for_publish',
                    subjectLine: "New Approved Cover Ready: {$itemTitle}",
                    headline: 'New Visual Asset Ready for Assignment',
                    summary: "{$eicName} approved \"{$itemTitle}\" ({$itemLabel}). It is now available in the Publish Article cover library.",
                    itemLabel: $itemLabel,
                    itemTitle: $itemTitle,
                    statusLabel: 'EIC Approved',
                    actorName: $eicName,
                    actionText: 'Open Publish Queue',
                    actionUrl: $url,
                ));
            }
        });
    }

    /**
     * Dispatch a notification closure after HTTP response with strict non-blocking fail-safe handling.
     */
    protected function dispatchSafely(string $context, callable $callback): void
    {
        try {
            dispatch(function () use ($context, $callback) {
                try {
                    $callback();
                } catch (\Throwable $e) {
                    Log::warning("Workflow email notification failed [{$context}]: " . $e->getMessage(), [
                        'exception' => $e,
                    ]);
                }
            })->afterResponse();
        } catch (\Throwable $e) {
            Log::warning("Unable to schedule workflow email notification [{$context}]: " . $e->getMessage());
        }
    }

    /**
     * Determine reviewers for a pending article stage.
     *
     * @return Collection<int, User>
     */
    protected function resolveArticleReviewerRecipients(Article $article, string $status, int $excludeUserId): Collection
    {
        if ($status === 'Pending President Review') {
            return $this->getUsersByRoleKeywords(['president'], $excludeUserId, exactMatches: ['President']);
        }

        if ($status === 'Pending EIC Review') {
            return $this->getUsersByRoleKeywords([
                'editor-in-chief',
                'associate editor-in-chief',
                'president',
                'vice president',
                'executive vice president',
            ], $excludeUserId);
        }

        $sectionHeads = $this->getSectionHeadsForCategory($article->category?->name, $excludeUserId);
        if ($sectionHeads->isNotEmpty()) {
            return $sectionHeads;
        }

        return $this->getUsersByRoleKeywords(['editor-in-chief', 'associate editor-in-chief'], $excludeUserId);
    }

    /**
     * Find active section heads matching a category name.
     *
     * @return Collection<int, User>
     */
    protected function getSectionHeadsForCategory(?string $categoryName, int $excludeUserId): Collection
    {
        $cat = strtolower(trim((string) $categoryName));
        if ($cat === '') {
            return collect();
        }

        $keywords = match (true) {
            str_contains($cat, 'news') => ['news head'],
            str_contains($cat, 'liter') => ['literary head'],
            str_contains($cat, 'feature') => ['feature head'],
            str_contains($cat, 'science') => ['science'],
            str_contains($cat, 'docu') || str_contains($cat, 'podcast') => ['documentary', 'podcast'],
            default => [],
        };

        if ($keywords === []) {
            return collect();
        }

        return $this->getUsersByRoleKeywords($keywords, $excludeUserId)
            ->filter(fn (User $u) => str_contains(strtolower((string) ($u->role?->role_name ?? '')), 'head'));
    }

    /**
     * Find active users with non-empty email matching any role keyword.
     *
     * @param  array<int, string>  $keywords
     * @param  array<int, string>  $exactMatches
     * @return Collection<int, User>
     */
    protected function getUsersByRoleKeywords(array $keywords, int $excludeUserId, array $exactMatches = []): Collection
    {
        $users = User::with('role')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get()
            ->filter(function (User $u) use ($keywords, $exactMatches) {
                $roleName = trim((string) ($u->role?->role_name ?? ''));
                if ($roleName === '') {
                    return false;
                }
                if ($exactMatches !== []) {
                    return in_array($roleName, $exactMatches, true);
                }
                $lowerRole = strtolower($roleName);
                foreach ($keywords as $kw) {
                    if (str_contains($lowerRole, strtolower($kw))) {
                        return true;
                    }
                }
                return false;
            });

        return $this->filterEligibleRecipients($users, $excludeUserId);
    }

    /**
     * Filter out deactivated, email-less, duplicate, and self recipients.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    protected function filterEligibleRecipients(Collection $users, int $excludeUserId): Collection
    {
        return $users
            ->filter(function ($u) use ($excludeUserId) {
                if (!$u instanceof User) {
                    return false;
                }
                if ((int) $u->id === $excludeUserId) {
                    return false;
                }
                if (strtolower((string) $u->account_status) === 'deactivated') {
                    return false;
                }
                $email = trim((string) $u->email);
                return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            })
            ->unique(fn (User $u) => strtolower(trim((string) $u->email)))
            ->values();
    }

    protected function frontendBaseUrl(): string
    {
        return rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
    }

    protected function buildUrlForUser(User $user, string $context): string
    {
        $base = $this->frontendBaseUrl();
        $portal = (new AuthController())->resolvePortal($user);

        $path = match ($context) {
            'review-articles' => match ($portal) {
                'eic' => '/eic/review-articles',
                'executive' => '/executive/review-articles',
                'section-head' => '/section-head/review-articles',
                default => "/{$portal}/dashboard",
            },
            'publish-article' => $portal === 'mpc' ? '/mpc/publish-article' : "/{$portal}/dashboard",
            'media-desk' => match ($portal) {
                'eic' => '/eic/media-desk',
                'mpc' => '/mpc/media-desk',
                'cartoonist' => '/cartoonist/cartoon-desk',
                default => "/{$portal}/dashboard",
            },
            'my-media' => match ($portal) {
                'cartoonist' => '/cartoonist/manage-cartoons',
                'mpc' => '/mpc/manage-media',
                'media' => '/media/manage-media',
                default => "/{$portal}/dashboard",
            },
            'my-articles' => match ($portal) {
                'eic' => '/eic/manage-my-articles',
                'executive' => '/executive/manage-my-articles',
                'section-head' => '/section-head/manage-my-articles',
                'mpc' => '/mpc/manage-my-articles',
                'writer', 'media', 'cartoonist' => "/{$portal}/manage-articles",
                default => '/writer/manage-articles',
            },
            default => "/{$portal}/dashboard",
        };

        return $base . $path;
    }

    protected function formatActorWithRole(User $user, string $fallbackName = 'Editorial Reviewer'): string
    {
        $user->loadMissing('role');
        $name = trim((string) ($user->full_name ?? '')) ?: $fallbackName;
        $role = trim((string) ($user->role?->role_name ?? ''));

        return $role !== '' ? "{$name} ({$role})" : $name;
    }
}
