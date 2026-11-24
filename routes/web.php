<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes (Headless API Mode)
|--------------------------------------------------------------------------
|
| The Lens operates as a decoupled headless architecture with a Vue 3 SPA frontend.
| Root requests serve health/service status, and session CSRF cookies are provided
| for SPA authentication. All application resources are served via /api routes.
|
*/

// Root Service Health Check
Route::get('/', function () {
    return response()->json([
        'service' => 'The Lens Publication API',
        'status' => 'online',
        'version' => '1.0.0',
    ]);
})->name('home');

// Sanctum CSRF Cookie Endpoint for SPA Authentication
Route::get('/sanctum/csrf-cookie', function () {
    return response()->json([
        'status' => 'ok',
        'csrf_token' => csrf_token(),
    ]);
});

// Fallback for undefined web routes
Route::fallback(function () {
    return response()->json([
        'message' => 'API endpoint not found. Please refer to /api routes.',
        'status' => 404,
    ], 404);
});
