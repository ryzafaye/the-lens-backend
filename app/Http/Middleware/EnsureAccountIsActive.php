<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    /**
     * Handle an incoming request.
     * Ensures deactivated user accounts cannot perform authenticated actions.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            /** @var \App\Models\User $user */
            $user = Auth::user();

            if ($user && ($user->isDeactivated() || strtolower(trim((string) ($user->account_status ?? ''))) === 'deactivated')) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                $message = 'Your account has been deactivated. Please contact an administrator.';

                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json([
                        'message' => $message,
                    ], 403);
                }

                return redirect('/login')->withErrors([
                    'student_number' => $message,
                ]);
            }
        }

        return $next($request);
    }
}
