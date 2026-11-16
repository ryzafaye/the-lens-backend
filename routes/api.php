<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PublicController;

/*
|--------------------------------------------------------------------
| Minimal API routes – Laravel automatically prefixes them with `/api`
| because the file is loaded via the `api:` key above.
|--------------------------------------------------------------------
*/

// GET /api/pages/public/home – JSON version of the public homepage
Route::get('pages/public/home', [PublicController::class, 'apiHome'])
    ->middleware('web');

// GET /api/pages/public/archives – JSON version of article archives with filters
Route::get('pages/public/archives', [PublicController::class, 'apiArchives'])
    ->middleware('web');


// POST /api/csrf-test – temporary endpoint to verify CSRF protection
Route::post('csrf-test', function () {
    // If we reach this point the CSRF token was valid.
    return response()->json(['status' => 'CSRF token accepted']);
})
->middleware('web');
