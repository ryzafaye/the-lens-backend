<?php

namespace App\Http\Controllers;

use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function updateInfo(Request $request, ImageUploadService $imageUploadService)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (!$user) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            return redirect()->route('login');
        }
        $user->loadMissing('role');

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:80'],
            'student_number' => ['required', 'string', 'max:30', 'unique:users,student_number,' . $user->id],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
        ]);

        $user->full_name = trim($validated['full_name']);
        $user->student_number = trim($validated['student_number']);

        $avatarUrl = null;
        $oldPublicId = $user->profile_picture_public_id;
        $oldUrl = $user->profile_picture_url;

        if ($request->hasFile('avatar')) {
            try {
                $result = $imageUploadService->uploadAvatar($request->file('avatar'));
                $user->profile_picture_url = $result['secure_url'];
                $user->profile_picture_public_id = $result['public_id'];
                $avatarUrl = $result['secure_url'];

                // On Replace: delete previous Cloudinary avatar after new upload succeeds
                if (!empty($oldPublicId) || !empty($oldUrl)) {
                    $imageUploadService->deleteImage($oldPublicId, $oldUrl);
                }
            } catch (\Throwable $e) {
                if ($request->wantsJson()) {
                    return response()->json([
                        'message' => 'Failed to upload profile picture to Cloudinary: ' . $e->getMessage(),
                    ], 500);
                }
                return back()->withErrors(['avatar' => 'Failed to upload image to Cloudinary: ' . $e->getMessage()]);
            }
        } elseif ($request->boolean('remove_avatar') || $request->input('remove_avatar') === '1') {
            // On Remove: delete existing image from Cloudinary, then set both URL and public_id to null
            if (!empty($oldPublicId) || !empty($oldUrl)) {
                $imageUploadService->deleteImage($oldPublicId, $oldUrl);
            }
            $user->profile_picture_url = null;
            $user->profile_picture_public_id = null;
            $avatarUrl = asset('images/member-placeholder.svg');
        }

        $user->save();
        Auth::setUser($user);
        \App\Auth\CachedEloquentUserProvider::clearCache($user->id);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Profile updated successfully',
                'profile_picture_url' => $user->profile_picture_url,
                'public_id' => $user->profile_picture_public_id,
                'profile_picture_public_id' => $user->profile_picture_public_id,
                'user' => [
                    'full_name' => $user->full_name,
                    'student_number' => $user->student_number,
                    'role' => $user->role ? $user->role->role_name : null,
                    'avatar_url' => $avatarUrl ?? $user->avatar_url,
                    'profile_picture_url' => $user->profile_picture_url,
                    'profile_picture_public_id' => $user->profile_picture_public_id,
                ]
            ]);
        }

        return back()->with('success', 'Profile updated successfully');
    }

    /**
     * Upload user profile avatar to Cloudinary.
     * Validates image (image|mimes:jpeg,png,jpg,webp|max:3072),
     * uploads via ImageUploadService, saves URL to user, and returns JSON.
     *
     * @param Request $request
     * @param ImageUploadService $imageUploadService
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadAvatar(Request $request, ImageUploadService $imageUploadService)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Support both 'image' and 'avatar' parameter names (default to 'image')
        $field = ($request->hasFile('avatar') || $request->has('avatar')) ? 'avatar' : 'image';

        $request->validate([
            $field => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:3072'],
        ]);

        $file = $request->file($field);

        $oldPublicId = $user->profile_picture_public_id;
        $oldUrl = $user->profile_picture_url;

        try {
            $uploadResult = $imageUploadService->uploadAvatar($file);

            $user->profile_picture_url = $uploadResult['secure_url'];
            $user->profile_picture_public_id = $uploadResult['public_id'];
            $user->save();

            // On Replace: delete previous Cloudinary avatar after new upload succeeds
            if (!empty($oldPublicId) || !empty($oldUrl)) {
                $imageUploadService->deleteImage($oldPublicId, $oldUrl);
            }

            Auth::setUser($user);
            \App\Auth\CachedEloquentUserProvider::clearCache($user->id);

            return response()->json([
                'message' => 'Profile picture uploaded successfully.',
                'profile_picture_url' => $uploadResult['secure_url'],
                'public_id' => $uploadResult['public_id'],
                'profile_picture_public_id' => $uploadResult['public_id'],
                'user' => [
                    'id' => $user->id,
                    'full_name' => $user->full_name,
                    'student_number' => $user->student_number,
                    'role' => $user->role ? $user->role->role_name : null,
                    'avatar_url' => $uploadResult['secure_url'],
                    'profile_picture_url' => $uploadResult['secure_url'],
                    'profile_picture_public_id' => $uploadResult['public_id'],
                ],
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to upload profile picture: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove user profile avatar from Cloudinary.
     * Deletes existing Cloudinary asset and resets profile_picture_url and profile_picture_public_id.
     *
     * @param Request $request
     * @param ImageUploadService $imageUploadService
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeAvatar(Request $request, ImageUploadService $imageUploadService)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $oldPublicId = $user->profile_picture_public_id;
        $oldUrl = $user->profile_picture_url;

        if (!empty($oldPublicId) || !empty($oldUrl)) {
            $imageUploadService->deleteImage($oldPublicId, $oldUrl);
        }

        $user->profile_picture_url = null;
        $user->profile_picture_public_id = null;
        $user->save();

        Auth::setUser($user);
        \App\Auth\CachedEloquentUserProvider::clearCache($user->id);

        return response()->json([
            'message' => 'Profile picture removed successfully.',
            'profile_picture_url' => null,
            'public_id' => null,
            'profile_picture_public_id' => null,
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'student_number' => $user->student_number,
                'role' => $user->role ? $user->role->role_name : null,
                'avatar_url' => asset('images/member-placeholder.svg'),
                'profile_picture_url' => null,
                'profile_picture_public_id' => null,
            ],
        ], 200);
    }

    public function updatePassword(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (!$user) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            return redirect()->route('login');
        }
        $user->loadMissing('role');

        $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:6', 'confirmed', 'different:current_password'],
        ], [
            'new_password.different' => 'The new password must be different from your current password.',
            'new_password.confirmed' => 'New password confirmation does not match.',
            'new_password.min' => 'The new password must be at least 6 characters.',
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'The provided current password does not match our records.'
                ], 422);
            }
            return back()->withErrors(['current_password' => 'The provided current password does not match our records.']);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();
        Auth::setUser($user);
        \App\Auth\CachedEloquentUserProvider::clearCache($user->id);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Password updated successfully'
            ]);
        }

        return back()->with('success', 'Password updated successfully');
    }
}

