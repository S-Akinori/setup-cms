<?php

use App\Http\Controllers\Api\ContentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::get('/posts', [ContentController::class, 'posts']);
    Route::get('/posts/{slug}', [ContentController::class, 'post']);
    Route::get('/content-manifest', [ContentController::class, 'manifest']);
    Route::get('/taxonomies', [ContentController::class, 'taxonomies']);
});
