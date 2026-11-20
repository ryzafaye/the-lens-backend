<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\WriterController;
use App\Http\Controllers\SectionHeadController;
use App\Http\Controllers\EicController;
use App\Http\Controllers\ExecutiveController;
use App\Http\Controllers\ProfileController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Routes registered here are automatically prefixed with `/api`.
| We apply the `web` middleware so session, cookies, and CSRF protection
| work seamlessly with the Vue SPA frontend.
|
*/

// GET /api/ping – initialize session and set CSRF cookie
Route::get('ping', function () {
    return response()->json([
        'status' => 'ok',
        'csrf_token' => csrf_token(),
    ]);
})->middleware('web');

// GET /api/sanctum/csrf-cookie – backwards-compatibility alias
Route::get('sanctum/csrf-cookie', function () {
    return response()->json([
        'status' => 'ok',
        'csrf_token' => csrf_token(),
    ]);
})->middleware('web');

// Auth endpoints
Route::post('login', [AuthController::class, 'login'])->middleware('web');
Route::post('logout', [AuthController::class, 'logout'])->middleware('web');
Route::get('user', [AuthController::class, 'user'])->middleware('web');

// Dashboard page endpoint for Vue SPA
Route::get('pages/dashboard', [AuthController::class, 'dashboardPage'])->middleware('web');

// Writer Portal endpoints (authenticated via web session)
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('writer/dashboard', [WriterController::class, 'dashboard']);
    Route::get('writer/submit-article', [WriterController::class, 'submitArticle']);
    Route::post('writer/submit-article', [WriterController::class, 'storeArticle']);
    Route::get('writer/manage-my-articles', [WriterController::class, 'manageMyArticles']);
    Route::delete('writer/drafts/{id}', [WriterController::class, 'destroyDraft']);
    Route::post('profile/update-info', [ProfileController::class, 'updateInfo']);
    Route::post('profile/update-password', [ProfileController::class, 'updatePassword']);
    Route::post('profile/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::delete('profile/avatar', [ProfileController::class, 'removeAvatar']);

    // Section Head Portal endpoints
    Route::get('section-head/dashboard', [SectionHeadController::class, 'dashboard']);
    Route::get('section-head/review-articles', [SectionHeadController::class, 'reviewArticles']);
    Route::match(['put', 'post'], 'section-head/review-articles/{id}', [SectionHeadController::class, 'updateReviewArticle']);
    Route::post('section-head/review-articles/{id}/approve', [SectionHeadController::class, 'approveArticle']);
    Route::post('section-head/review-articles/{id}/revision', [SectionHeadController::class, 'returnForRevision']);
    Route::get('section-head/manage-my-articles', [SectionHeadController::class, 'manageMyArticles']);
    Route::get('section-head/submit-article', [SectionHeadController::class, 'submitArticle']);
    Route::post('section-head/submit-article', [SectionHeadController::class, 'storeArticle']);
    Route::delete('section-head/drafts/{id}', [SectionHeadController::class, 'destroyDraft']);
    Route::get('section-head/activity-logs', [SectionHeadController::class, 'activityLogs']);

    // Editor-in-Chief & Associate Editor-in-Chief Portal endpoints
    Route::get('eic/dashboard', [EicController::class, 'dashboard']);
    Route::get('eic/manage-articles', [EicController::class, 'manageArticles']);
    Route::post('eic/manage-articles/{id}/archive', [EicController::class, 'archiveArticle']);
    Route::post('eic/manage-articles/{id}/restore', [EicController::class, 'restoreArticle']);
    Route::match(['put', 'post'], 'eic/manage-articles/{id}', [EicController::class, 'updateArticle']);
    Route::delete('eic/manage-articles/{id}', [EicController::class, 'destroyArticle']);
    Route::get('eic/manage-my-articles', [EicController::class, 'manageMyArticles']);
    Route::get('eic/review-articles', [EicController::class, 'reviewArticles']);
    Route::match(['put', 'post'], 'eic/review-articles/{id}', [EicController::class, 'updateReviewArticle']);
    Route::post('eic/review-articles/{id}/approve', [EicController::class, 'approveArticle']);
    Route::post('eic/review-articles/{id}/revision', [EicController::class, 'returnForRevision']);
    Route::get('eic/media-desk', [EicController::class, 'mediaDesk']);
    Route::post('eic/media/{id}/approve', [EicController::class, 'approveMedia']);
    Route::post('eic/media/{id}/revision', [EicController::class, 'returnMediaForRevision']);
    Route::get('eic/submit-article', [EicController::class, 'submitArticle']);
    Route::post('eic/submit-article', [EicController::class, 'storeArticle']);
    Route::delete('eic/drafts/{id}', [EicController::class, 'destroyDraft']);
    Route::get('eic/manage-accounts', [EicController::class, 'manageAccounts']);
    Route::post('eic/manage-accounts', [EicController::class, 'storeAccount']);
    Route::put('eic/manage-accounts/{id}/role', [EicController::class, 'updateRole']);
    Route::patch('eic/manage-accounts/{id}/status', [EicController::class, 'toggleStatus']);
    Route::get('eic/activity-logs', [EicController::class, 'activityLogs']);
    Route::get('eic/feedback-inbox', [EicController::class, 'feedbackInbox']);
    Route::get('eic/profile-settings', [EicController::class, 'profileSettings']);

    // Executive Portal endpoints
    Route::get('executive/dashboard', [ExecutiveController::class, 'dashboard']);
    Route::get('executive/manage-articles', [ExecutiveController::class, 'manageArticles']);
    Route::post('executive/manage-articles/{id}/archive', [ExecutiveController::class, 'archiveArticle']);
    Route::post('executive/manage-articles/{id}/restore', [ExecutiveController::class, 'restoreArticle']);
    Route::match(['put', 'post'], 'executive/manage-articles/{id}', [ExecutiveController::class, 'updateArticle']);
    Route::delete('executive/manage-articles/{id}', [ExecutiveController::class, 'destroyArticle']);
    Route::get('executive/manage-my-articles', [ExecutiveController::class, 'manageMyArticles']);
    Route::get('executive/review-articles', [ExecutiveController::class, 'reviewArticles']);
    Route::match(['put', 'post'], 'executive/review-articles/{id}', [ExecutiveController::class, 'updateReviewArticle']);
    Route::post('executive/review-articles/{id}/approve', [ExecutiveController::class, 'approveArticle']);
    Route::post('executive/review-articles/{id}/revision', [ExecutiveController::class, 'returnForRevision']);
    Route::get('executive/submit-article', [ExecutiveController::class, 'submitArticle']);
    Route::post('executive/submit-article', [ExecutiveController::class, 'storeArticle']);
    Route::delete('executive/drafts/{id}', [ExecutiveController::class, 'destroyDraft']);
    Route::get('executive/manage-accounts', [ExecutiveController::class, 'manageAccounts']);
    Route::post('executive/manage-accounts', [ExecutiveController::class, 'storeAccount']);
    Route::put('executive/manage-accounts/{id}/role', [ExecutiveController::class, 'updateRole']);
    Route::patch('executive/manage-accounts/{id}/status', [ExecutiveController::class, 'toggleStatus']);
    Route::get('executive/activity-logs', [ExecutiveController::class, 'activityLogs']);
    Route::get('executive/feedback-inbox', [ExecutiveController::class, 'feedbackInbox']);
    Route::get('executive/feedback', [ExecutiveController::class, 'feedbackInbox']);
    Route::get('executive/transparency', [ExecutiveController::class, 'transparency']);
    Route::get('executive/profile-settings', [ExecutiveController::class, 'profileSettings']);
});

// Public page endpoints
Route::get('pages/public/home', [PublicController::class, 'apiHome'])->middleware('web');
Route::get('pages/public/archives', [PublicController::class, 'apiArchives'])->middleware('web');

// POST /api/csrf-test – endpoint to verify CSRF protection
Route::post('csrf-test', function () {
    return response()->json(['status' => 'CSRF token accepted']);
})->middleware('web');
