<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use App\Auth\CachedEloquentUserProvider;
use App\Mail\Transports\BrevoApiTransport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register the high-performance cached user provider to eliminate remote database roundtrips on auth
        Auth::provider('cached-eloquent', function ($app, array $config) {
            return new CachedEloquentUserProvider($app['hash'], $config['model']);
        });

        // Globally eager load the user's role relation when authenticated to prevent N+1 queries in views/layouts/sidebars
        View::composer('*', function ($view) {
            if (Auth::check()) {
                /** @var \App\Models\User $user */
                $user = Auth::user();
                $user?->loadMissing('role');
            }
        });

        // Register Brevo HTTPS API Mail Transport
        Mail::extend('brevo', function (array $config = []) {
            return new BrevoApiTransport($config['key'] ?? config('services.brevo.key'));
        });

        // Point password reset links directly to the Vue SPA
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
            $email = method_exists($notifiable, 'getEmailForPasswordReset')
                ? $notifiable->getEmailForPasswordReset()
                : ($notifiable->email ?? '');

            return $frontendUrl . '/reset-password?token=' . urlencode($token) . '&email=' . urlencode((string) $email);
        });

        // Render branded maroon-and-white HTML template for password reset emails
        ResetPassword::toMailUsing(function (object $notifiable, string $token): MailMessage {
            $resetUrl = ResetPassword::$createUrlCallback
                ? call_user_func(ResetPassword::$createUrlCallback, $notifiable, $token)
                : rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/')
                    . '/reset-password?token=' . urlencode($token)
                    . '&email=' . urlencode((string) ($notifiable->email ?? ''));

            $expireMinutes = (int) config('auth.passwords.' . config('auth.defaults.passwords', 'users') . '.expire', 60);

            return (new MailMessage)
                ->subject('Reset Your Password — The Lens Publication')
                ->view('emails.password-reset', [
                    'recipientName' => $notifiable->full_name ?? 'Member',
                    'studentNumber' => $notifiable->student_number ?? null,
                    'resetUrl' => $resetUrl,
                    'expireMinutes' => $expireMinutes,
                ]);
        });
    }
}
