<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class PublicationOfficerService
{
    /**
     * Standard hierarchy and display order for Executive Board officers.
     */
    public const EXECUTIVE_ROLE_ORDER = [
        'President' => 1,
        'Executive Vice President' => 2,
        'Vice President' => 2,
        'Secretary' => 3,
        'Associate Secretary' => 4,
        'Treasurer' => 5,
        'Auditor' => 6,
        'Public Relations Officer' => 7,
    ];

    /**
     * Retrieve public officers for the Publication Officers / Executive Board section.
     * Caches the output using Cache::remember for fast response times.
     * Strictly exposes only safe public attributes.
     *
     * @return array{officers: array<int, array{id: int, name: string, full_name: string, role: string, position: string, board: string, sort_order: int, profile_picture_url: ?string, avatar: ?string}>, count: int}
     */
    public function getPublicOfficers(): array
    {
        return Cache::remember('public_publication_officers_v2', 3600, function () {
            $roleNames = array_keys(self::EXECUTIVE_ROLE_ORDER);

            $users = User::with('role')
                ->whereHas('role', function ($query) use ($roleNames) {
                    $query->whereIn('role_name', $roleNames);
                })
                ->where(function ($query) {
                    $query->where('account_status', 'active')
                          ->orWhereNull('account_status');
                })
                ->get();

            $officers = $users->map(function (User $user) {
                $roleName = $user->role->role_name ?? 'Officer';
                $sortOrder = self::EXECUTIVE_ROLE_ORDER[$roleName] ?? 99;

                $profilePic = $user->profile_picture_url;
                $optimizedPic = null;
                if ($profilePic) {
                    $optimizedPic = User::optimizeCloudinaryUrl($profilePic, 'w_300,c_limit,f_auto,q_auto');
                }

                return [
                    'id' => (int) $user->id,
                    'name' => (string) $user->full_name,
                    'full_name' => (string) $user->full_name,
                    'role' => $roleName,
                    'position' => $roleName,
                    'board' => 'Executive Board',
                    'sort_order' => $sortOrder,
                    'profile_picture_url' => $optimizedPic,
                    'avatar' => $optimizedPic,
                ];
            })
            ->sortBy('sort_order')
            ->values()
            ->all();

            return [
                'officers' => $officers,
                'count' => count($officers),
            ];
        });
    }

    /**
     * Clear the public officers cache.
     */
    public function clearCache(): void
    {
        Cache::forget('public_publication_officers_v2');
    }
}
