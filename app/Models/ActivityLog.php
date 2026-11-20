<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    use HasFactory;

    protected $table = 'activity_logs';

    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
    ];

    /**
     * The "booted" method of the model.
     * Automatically filters out all login, logout, and profile settings events system-wide.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('exclude_auth_logs', function ($builder) {
            $builder->where(function ($q) {
                $q->whereRaw("LOWER(action) NOT LIKE '%login%'")
                  ->whereRaw("LOWER(action) NOT LIKE '%logged in%'")
                  ->whereRaw("LOWER(action) NOT LIKE '%logout%'")
                  ->whereRaw("LOWER(action) NOT LIKE '%logged out%'")
                  ->whereRaw("LOWER(action) NOT LIKE '%profile%'")
                  ->whereRaw("LOWER(action) NOT LIKE '%password%'");
            });
        });
    }

    /**
     * Relationship: The user (causer) who performed the action.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Polymorphic Relationship: The affected model/entity.
     */
    public function entity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'entity_type', 'entity_id');
    }

    /**
     * Static helper method to easily record an activity log from any controller.
     * Usage: ActivityLog::logAction(Auth::id(), 'approved', $article);
     *
     * @param int|string $userId
     * @param string $action e.g. 'submitted', 'approved', 'published', 'returned for revision', 'updated'
     * @param \Illuminate\Database\Eloquent\Model|null $entity
     * @return static
     */
    public static function logAction($userId, string $action, ?Model $entity = null): self
    {
        $actionLower = strtolower(trim($action));
        if (
            str_contains($actionLower, 'login') ||
            str_contains($actionLower, 'logged in') ||
            str_contains($actionLower, 'logout') ||
            str_contains($actionLower, 'logged out') ||
            str_contains($actionLower, 'profile') ||
            str_contains($actionLower, 'password')
        ) {
            // Do not record login, logout, or profile settings events in activity logs
            return new static();
        }

        $entityType = $entity ? get_class($entity) : null;
        if ($entityType === 'App\\Models\\Cartoon') {
            $entityType = null;
            $entityId = null;
        } else {
            $entityId = $entity ? $entity->getKey() : null;
        }

        return static::create([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
    }

    /**
     * Format the log item for consumption by timeline views and dashboard widgets.
     */
    public function formatForTimeline(): array
    {
        $user = $this->user;
        $entity = $this->entity;

        $subjectName = 'Record';
        $subjectType = 'Article';

        if ($entity instanceof Article) {
            $subjectName = $entity->title ?? 'Untitled Article';
            static $categoryMap = null;
            if ($categoryMap === null) {
                $categoryMap = \Illuminate\Support\Facades\Cache::remember('all_categories_map_id_name', 3600, function () {
                    try {
                        return \App\Models\Category::pluck('name', 'id')->all();
                    } catch (\Throwable $e) {
                        return [];
                    }
                });
            }
            $catId = $entity->category_id ?? null;
            $catName = ($catId && isset($categoryMap[$catId])) ? $categoryMap[$catId] : ($entity->relationLoaded('category') ? ($entity->category->name ?? '') : '');
            $categoryName = strtolower($catName);
            if (str_contains($categoryName, 'cartoon')) {
                $subjectType = 'Editorial Cartoon';
            } elseif (str_contains($categoryName, 'media') || str_contains($categoryName, 'graphic') || str_contains($categoryName, 'pubmat')) {
                $subjectType = 'Graphic Material';
            } else {
                $subjectType = 'Article';
            }
        } elseif ($entity instanceof User) {
            $subjectName = $entity->full_name ?? 'User Account';
            $subjectType = 'Officer Account';
        } elseif ($entity) {
            $subjectName = $entity->title ?? $entity->name ?? $entity->subject ?? ('#' . $this->entity_id);
            $subjectType = class_basename($entity);
        } else {
            if (stripos($this->action, 'illustration') !== false || stripos($this->action, 'cartoon') !== false) {
                $subjectType = 'Editorial Cartoon';
                $subjectName = 'Editorial Artwork';
            } elseif (stripos($this->action, 'media') !== false || stripos($this->action, 'pubmat') !== false) {
                $subjectType = 'Media Asset';
                $subjectName = 'Publication Material';
            } elseif (stripos($this->action, 'login') !== false || stripos($this->action, 'logged in') !== false) {
                $subjectType = 'Portal Session';
                $subjectName = 'The Lens Workspace';
            } elseif (stripos($this->action, 'profile') !== false || stripos($this->action, 'password') !== false) {
                $subjectType = 'Account Security';
                $subjectName = 'User Profile';
            } else {
                $subjectType = 'Editorial Item';
                $subjectName = 'Desk Record';
            }
        }

        $displayAction = $this->action;
        if ($displayAction === 'submitted illustration' || $displayAction === 'submitted media') {
            $displayAction = 'submitted';
        }

        $createdAt = $this->created_at ?? now();
        $isToday = $createdAt->isToday();
        $isYesterday = $createdAt->isYesterday();
        $dayGroup = $isToday ? 'Today' : ($isYesterday ? 'Yesterday' : $createdAt->format('M d, Y'));

        $statementParts = static::formatStatementParts($displayAction, $subjectType, $subjectName);

        return [
            'id' => (string) $this->id,
            'causer' => $user?->full_name ?? 'System',
            'causerRole' => $user?->role?->role_name ?? 'Member',
            'causerAvatar' => $user?->avatar_url ?? ($user?->avatar ? asset($user->avatar) : asset('images/member-placeholder.svg')),
            'action' => $displayAction,
            'subjectType' => $subjectType,
            'subjectName' => $subjectName,
            'statementBefore' => $statementParts['before_subject'],
            'statementAfter' => $statementParts['after_subject'],
            'statementSentence' => $statementParts['statement_sentence'],
            'timestampText' => $createdAt->diffForHumans(),
            'formattedDate' => $createdAt->format('M d, Y • g:i A'),
            'isoDate' => $createdAt->toIso8601String(),
            'dateKey' => $createdAt->format('Y-m-d'),
            'dayGroup' => $dayGroup,
            'user' => $user?->full_name ?? 'System',
            'avatar' => $user?->avatar_url ?? ($user?->avatar ? asset($user->avatar) : asset('images/member-placeholder.svg')),
            'target' => $subjectName,
            'time' => $createdAt->diffForHumans(),
        ];
    }

    /**
     * Format action, subject type, and subject name into grammatically correct sentence clauses.
     *
     * @param string $action
     * @param string $subjectType
     * @param string|null $subjectName
     * @return array{before_subject: string, subject_name: string, after_subject: string, statement_sentence: string}
     */
    public static function formatStatementParts(string $action, string $subjectType, ?string $subjectName): array
    {
        $actionLower = strtolower(trim($action));
        $lowerType = strtolower(trim($subjectType));
        if (in_array($lowerType, ['record', 'desk record', 'editorial item', ''])) {
            $lowerType = 'article';
        }

        $typePhrase = "the {$lowerType}";
        if (in_array($lowerType, ['officer account', 'user account', 'user'])) {
            $typePhrase = 'the officer account';
        } elseif (in_array($lowerType, ['photo', 'photograph'])) {
            $typePhrase = 'the photo';
        } elseif (in_array($lowerType, ['media asset', 'graphic material', 'graphic'])) {
            $typePhrase = 'the media asset';
        } elseif (in_array($lowerType, ['editorial cartoon', 'cartoon'])) {
            $typePhrase = 'the editorial cartoon';
        }

        $cleanSubjectName = trim($subjectName ?? '');
        if (in_array(strtolower($cleanSubjectName), ['record', 'desk record', 'untitled article', 'editorial item'])) {
            $cleanSubjectName = '';
        }

        $beforeSubject = '';
        $afterSubject = '';

        if ($actionLower === 'edited manuscript' || $actionLower === 'edited article') {
            $beforeSubject = "edited the manuscript for {$typePhrase}";
        } elseif ($actionLower === 'returned for revision' || $actionLower === 'returned article for revision') {
            $beforeSubject = "returned {$typePhrase}";
            $afterSubject = 'for revision';
        } elseif (str_starts_with($actionLower, 'returned') && str_contains($actionLower, 'revision')) {
            $noun = $typePhrase;
            if (str_contains($actionLower, 'cartoon')) {
                $noun = 'the editorial cartoon';
            } elseif (str_contains($actionLower, 'photo')) {
                $noun = 'the photo';
            } elseif (str_contains($actionLower, 'visual')) {
                $noun = 'the visual asset';
            }
            $beforeSubject = "returned {$noun}";
            $afterSubject = 'for revision';
        } elseif (str_starts_with($actionLower, 'endorsed') && str_contains($actionLower, 'eic')) {
            $noun = $typePhrase;
            if (str_contains($actionLower, 'cartoon')) {
                $noun = 'the editorial cartoon';
            } elseif (str_contains($actionLower, 'photo')) {
                $noun = 'the photo';
            } elseif (str_contains($actionLower, 'visual')) {
                $noun = 'the visual asset';
            }
            $beforeSubject = "endorsed {$noun}";
            $afterSubject = 'to the EIC';
        } elseif ($actionLower === 'assigned cover to') {
            $beforeSubject = "assigned a cover to {$typePhrase}";
        } elseif (in_array($actionLower, ['created account for', 'created user'])) {
            $beforeSubject = 'created an officer account for';
        } elseif ($actionLower === 'updated role for') {
            $beforeSubject = 'updated the role for';
        } elseif ($actionLower === 'deactivated account for') {
            $beforeSubject = 'deactivated the account for';
        } elseif ($actionLower === 'activated account for') {
            $beforeSubject = 'activated the account for';
        } elseif ($actionLower === 'approved visual artwork') {
            $beforeSubject = 'approved the visual artwork';
        } elseif ($actionLower === 'submitted editorial cartoon') {
            $beforeSubject = 'submitted the editorial cartoon';
        } elseif ($actionLower === 'submitted photo') {
            $beforeSubject = 'submitted the photo';
        } elseif (in_array($actionLower, ['submitted media', 'submitted illustration'])) {
            $beforeSubject = "submitted {$typePhrase}";
        } elseif (in_array($actionLower, ['emergency delete', 'emergency deleted', 'hard delete', 'hard deleted', 'permanently delete', 'permanently deleted'])) {
            $beforeSubject = "permanently deleted {$typePhrase}";
        } elseif (in_array($actionLower, ['deleted', 'deleted article'])) {
            $beforeSubject = "deleted {$typePhrase}";
        } elseif (in_array($actionLower, ['rejected', 'declined', 'rejected article', 'declined article'])) {
            $beforeSubject = "rejected {$typePhrase}";
        } elseif (in_array($actionLower, ['submitted', 'submitted article'])) {
            $beforeSubject = "submitted {$typePhrase}";
        } elseif (in_array($actionLower, ['approved', 'approved article'])) {
            $beforeSubject = "approved {$typePhrase}";
        } elseif (in_array($actionLower, ['published', 'published article'])) {
            $beforeSubject = "published {$typePhrase}";
        } elseif (in_array($actionLower, ['unpublished', 'unpublished article'])) {
            $beforeSubject = "unpublished {$typePhrase}";
        } elseif (in_array($actionLower, ['archived', 'archived article'])) {
            $beforeSubject = "archived {$typePhrase}";
        } elseif (in_array($actionLower, ['restored', 'restored article'])) {
            $beforeSubject = "restored {$typePhrase}";
        } elseif (in_array($actionLower, ['submitted', 'approved', 'published', 'unpublished', 'archived', 'restored'])) {
            $beforeSubject = "{$actionLower} {$typePhrase}";
        } else {
            if (str_ends_with($actionLower, ' for') || str_ends_with($actionLower, ' to') || str_ends_with($actionLower, ' of')) {
                $beforeSubject = "{$action} {$typePhrase}";
            } else {
                $beforeSubject = "{$action} {$typePhrase}";
            }
        }

        $nameQuote = $cleanSubjectName !== '' ? " \"{$cleanSubjectName}\"" : '';
        $afterText = $afterSubject !== '' ? " {$afterSubject}" : '';

        return [
            'before_subject' => $beforeSubject,
            'subject_name' => $cleanSubjectName,
            'after_subject' => $afterSubject,
            'statement_sentence' => trim("{$beforeSubject}{$nameQuote}{$afterText}"),
        ];
    }
}
