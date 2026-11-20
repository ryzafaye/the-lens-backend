<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('login');
    }

    public function getUserRoleName($user): string
    {
        if (!$user) {
            return '';
        }

        // 1. Direct relationship object
        if (is_object($user->role) && isset($user->role->role_name)) {
            $name = trim((string) $user->role->role_name);
            if (!empty($name)) {
                return $name;
            }
        }

        // 2. Direct string attribute on model (if role column exists)
        $roleAttr = $user->getAttribute('role');
        if (is_string($roleAttr) && !empty(trim($roleAttr))) {
            return trim($roleAttr);
        }

        // 3. role_name attribute on model (e.g. from query join)
        $roleNameAttr = $user->getAttribute('role_name');
        if (is_string($roleNameAttr) && !empty(trim($roleNameAttr))) {
            return trim($roleNameAttr);
        }

        // 4. Role relation as array
        if (is_array($user->role) && !empty($user->role['role_name'])) {
            return trim((string) $user->role['role_name']);
        }

        // 5. Query via role_id if relation was not loaded
        if (!empty($user->role_id)) {
            try {
                $roleModel = \App\Models\Role::find($user->role_id);
                if ($roleModel && !empty($roleModel->role_name)) {
                    return trim((string) $roleModel->role_name);
                }
            } catch (\Throwable $e) {
                // Ignore query error if DB unreachable
            }
        }

        return '';
    }

    public function resolvePortal($user): string
    {
        if (!$user) {
            return 'writer';
        }

        if (!$user->relationLoaded('role') || $user->role === null) {
            $user->load('role');
        }

        $rawRoleName = $this->getUserRoleName($user);
        $roleName = strtolower(trim($rawRoleName));

        if (empty($roleName)) {
            return 'writer';
        }

        // 1. Writer roles (Staff Writer, News Writer, Feature Writer, Writer, etc.)
        if (str_contains($roleName, 'writer')) {
            return 'writer';
        }

        // 2. Cartoonist: Cartoonist Head -> section-head, otherwise -> cartoonist
        if (str_contains($roleName, 'cartoonist')) {
            return str_contains($roleName, 'head') ? 'section-head' : 'cartoonist';
        }

        // 3. Media and Public Communications (M&PC / Media and Public)
        if (str_contains($roleName, 'm&pc') || str_contains($roleName, 'media and public')) {
            return 'mpc';
        }

        // 4. Editor-in-Chief & Associate EIC
        if (
            str_contains($roleName, 'editor-in-chief') ||
            (str_contains($roleName, 'eic') && !str_contains($roleName, 'section')) ||
            $roleName === 'associate editor-in-chief' ||
            str_contains($roleName, 'chief')
        ) {
            return 'eic';
        }

        // 5. Section Heads (News Head, Literary Head, Feature Head, Science Head, Photojournalist Head, etc.)
        if (str_contains($roleName, 'head') || str_contains($roleName, 'section head')) {
            return 'section-head';
        }

        // 6. Media Team / Photojournalist
        if (
            str_contains($roleName, 'media team') ||
            $roleName === 'media & communication' ||
            str_contains($roleName, 'photojournalist') ||
            str_contains($roleName, 'media')
        ) {
            return 'media';
        }

        // 7. Executive Officers
        $executiveRoles = [
            'president', 'executive vice president', 'vice president',
            'secretary', 'associate secretary', 'treasurer', 'auditor',
            'public relations officer', 'public relations', 'pro', 'executive'
        ];
        foreach ($executiveRoles as $execRole) {
            if (str_contains($roleName, $execRole)) {
                return 'executive';
            }
        }

        return 'writer';
    }

    public function formatUserData($user): ?array
    {
        if (!$user) {
            return null;
        }

        if (!$user->relationLoaded('role') || $user->role === null) {
            $user->load('role');
        }

        $portal = $this->resolvePortal($user);
        $roleName = $this->getUserRoleName($user);

        return [
            'id' => $user->id,
            'student_number' => $user->student_number,
            'studentNumber' => $user->student_number,
            'name' => $user->full_name,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'role' => $roleName ?: ($portal === 'writer' ? 'Writer' : ucfirst($portal)),
            'portal' => $portal,
            'avatar_url' => $user->avatar_url,
            'avatarUrl' => $user->avatar_url,
            'profile_picture_url' => $user->profile_picture_url,
            'profile_picture_public_id' => $user->profile_picture_public_id,
        ];
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'student_number' => ['required', 'string'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();

            /** @var \App\Models\User $user */
            $user = Auth::user();
            if ($user) {
                \App\Auth\CachedEloquentUserProvider::clearCache($user->getAuthIdentifier());
                $user->load('role');
            }

            $portal = $this->resolvePortal($user);

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Login successful',
                    'user' => $this->formatUserData($user),
                    'csrf_token' => csrf_token(),
                    'redirect' => "/{$portal}/dashboard",
                ]);
            }

            // Traditional Blade redirects
            return match ($portal) {
                'cartoonist' => redirect()->route('cartoonist.dashboard'),
                'media' => redirect()->route('media.dashboard'),
                'eic' => redirect()->route('eic.dashboard'),
                'mpc' => redirect()->route('mpc.dashboard'),
                'section-head' => redirect()->route('section-head.dashboard'),
                'writer' => redirect()->route('writer.dashboard'),
                'executive' => redirect()->route('executive.dashboard'),
                default => redirect()->route('writer.dashboard'),
            };
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'Invalid student number or password.',
                'errors' => [
                    'student_number' => ['Invalid student number or password.'],
                ],
            ], 422);
        }

        return back()->withErrors([
            'student_number' => 'Invalid student number or password.',
        ])->onlyInput('student_number');
    }

    public function logout(Request $request)
    {
        $userId = Auth::id();
        Auth::logout();
        \App\Auth\CachedEloquentUserProvider::clearCache($userId);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'Logged out successfully',
            ]);
        }

        return redirect('/login');
    }

    public function user(Request $request)
    {
        $user = Auth::user();
        return response()->json([
            'user' => $this->formatUserData($user),
        ]);
    }

    public function dashboardPage(Request $request)
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $defaultPortal = $this->resolvePortal($user);
        $portal = $request->query('portal') ?: $defaultPortal;

        try {
            $view = match ($portal) {
                'executive' => app(\App\Http\Controllers\ExecutiveController::class)->dashboard(),
                'writer' => app(\App\Http\Controllers\WriterController::class)->dashboard(),
                'section-head' => app(\App\Http\Controllers\SectionHeadController::class)->dashboard(),
                'eic' => app(\App\Http\Controllers\EicController::class)->dashboard(),
                'mpc' => app(\App\Http\Controllers\MpcController::class)->dashboard(),
                'cartoonist' => app(\App\Http\Controllers\CartoonistController::class)->dashboard(),
                'media' => app(\App\Http\Controllers\MediaController::class)->dashboard(),
                default => app(\App\Http\Controllers\WriterController::class)->dashboard(),
            };
            $data = $view instanceof \Illuminate\View\View ? $view->getData() : [];
        } catch (\Throwable $e) {
            $data = [];
        }

        $props = array_merge([
            'title' => ucfirst($portal) . ' Dashboard',
            'portal' => $portal,
        ], $data);

        return response()->json([
            'props' => $props,
            'shared' => [
                'auth' => [
                    'user' => $this->formatUserData($user),
                ],
                'flash' => (object) [],
                'errors' => (object) [],
            ],
        ]);
    }
}
