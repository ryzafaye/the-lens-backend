<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\CachedEloquentUserProvider;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /**
     * Handle forgot-password requests by Student Number (or Email).
     * If the student number matches an account without an email saved yet,
     * prompts for an email (or saves the submitted email onto the user account)
     * before dispatching the password reset link.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $studentNumber = strtoupper(trim((string) $request->input('student_number', '')));
        $emailInput = strtolower(trim((string) $request->input('email', '')));

        $request->merge([
            'student_number' => $studentNumber !== '' ? $studentNumber : null,
            'email' => $emailInput !== '' ? $emailInput : null,
        ]);

        $validated = $request->validate([
            'student_number' => ['nullable', 'required_without:email', 'string', 'max:30'],
            'email' => ['nullable', 'required_without:student_number', 'string', 'email:rfc', 'max:255'],
        ], [
            'student_number.required_without' => 'Please enter your student number or email address.',
            'email.required_without' => 'Please enter your student number or email address.',
            'email.email' => 'Please enter a valid email address.',
        ]);

        $emailSaved = false;
        $user = null;

        if (!empty($validated['student_number'])) {
            $user = User::with('role')
                ->where('student_number', $validated['student_number'])
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'We could not find an account matching that student number.',
                    'errors' => [
                        'student_number' => ['We could not find an account matching that student number.'],
                    ],
                ], 422);
            }

            if (strtolower((string) $user->account_status) === 'deactivated') {
                return response()->json([
                    'success' => false,
                    'message' => 'This account is currently deactivated. Please contact the Editor-in-Chief or Executive board.',
                ], 403);
            }

            $existingEmail = strtolower(trim((string) $user->email));
            if ($existingEmail === '') {
                if (empty($validated['email'])) {
                    return response()->json([
                        'success' => true,
                        'needs_email' => true,
                        'student_number' => $user->student_number,
                        'full_name' => $user->full_name,
                        'message' => 'No email address is registered on your account yet. Please enter your email address—this will also be saved to your system profile.',
                    ]);
                }

                $newEmail = strtolower(trim((string) $validated['email']));
                $emailTaken = User::whereRaw('LOWER(email) = ?', [$newEmail])
                    ->where('id', '!=', $user->id)
                    ->exists();

                if ($emailTaken) {
                    return response()->json([
                        'success' => false,
                        'needs_email' => true,
                        'message' => 'This email address is already registered to another account.',
                        'errors' => [
                            'email' => ['This email address is already registered to another account.'],
                        ],
                    ], 422);
                }

                $user->email = $newEmail;
                $user->save();
                CachedEloquentUserProvider::clearCache($user->id);
                Cache::forget('all_officers_list');
                $emailSaved = true;
            }
        } else {
            $lookupEmail = strtolower(trim((string) $validated['email']));
            $user = User::with('role')
                ->whereRaw('LOWER(email) = ?', [$lookupEmail])
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'We could not find an account with that email address.',
                    'errors' => [
                        'email' => ['We could not find an account with that email address.'],
                    ],
                ], 422);
            }

            if (strtolower((string) $user->account_status) === 'deactivated') {
                return response()->json([
                    'success' => false,
                    'message' => 'This account is currently deactivated. Please contact the Editor-in-Chief or Executive board.',
                ], 403);
            }
        }

        try {
            $status = Password::broker()->sendResetLink([
                'email' => $user->email,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to dispatch password reset email: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'student_number' => $user->student_number,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to send the password reset email right now. Please try again later.',
            ], 500);
        }

        if ($status === Password::RESET_THROTTLED) {
            return response()->json([
                'success' => false,
                'message' => 'Please wait a moment before requesting another password reset link.',
            ], 429);
        }

        if ($status !== Password::RESET_LINK_SENT) {
            return response()->json([
                'success' => false,
                'message' => __($status),
            ], 422);
        }

        $maskedEmail = $this->maskEmail((string) $user->email);

        return response()->json([
            'success' => true,
            'needs_email' => false,
            'email_saved' => $emailSaved,
            'masked_email' => $maskedEmail,
            'message' => $emailSaved
                ? "Your email address ({$user->email}) has been saved to your account and a password reset link has been sent to {$maskedEmail}."
                : "A password reset link has been sent to {$maskedEmail}. Please check your inbox and spam folder.",
        ]);
    }

    /**
     * Reset the user's password using a valid token from password_reset_tokens.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email', ''))),
        ]);

        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'password.min' => 'Password must be at least 6 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
        ]);

        $status = Password::broker()->reset(
            [
                'email' => $validated['email'],
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $validated['token'],
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));
                $user->save();

                CachedEloquentUserProvider::clearCache($user->id);
                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'success' => false,
                'message' => __($status),
                'errors' => [
                    'email' => [__($status)],
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Your password has been reset successfully. You can now sign in with your new password.',
        ]);
    }

    protected function maskEmail(string $email): string
    {
        $parts = explode('@', trim($email), 2);
        if (count($parts) !== 2) {
            return $email;
        }

        [$local, $domain] = $parts;
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));
        $stars = str_repeat('*', max(3, mb_strlen($local) - mb_strlen($visible)));

        return $visible . $stars . '@' . $domain;
    }
}
