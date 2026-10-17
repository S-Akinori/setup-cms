<?php

use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\ContentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::get('/posts', [ContentController::class, 'posts']);
    Route::get('/posts/{slug}', [ContentController::class, 'post']);
    Route::get('/posts/{slug}/comments', [CommentController::class, 'postIndex']);
    Route::post('/posts/{slug}/comments', [CommentController::class, 'postStore'])->middleware('throttle:5,1');
    Route::get('/pages', [ContentController::class, 'pages']);
    Route::get('/pages/{slug}', [ContentController::class, 'page']);
    Route::get('/pages/{slug}/comments', [CommentController::class, 'pageIndex']);
    Route::post('/pages/{slug}/comments', [CommentController::class, 'pageStore'])->middleware('throttle:5,1');
    Route::get('/content-manifest', [ContentController::class, 'manifest']);
    Route::get('/taxonomies', [ContentController::class, 'taxonomies']);
});
