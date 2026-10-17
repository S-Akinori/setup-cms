<?php

use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CommentController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\PostController;
use App\Http\Controllers\Admin\WordPressImportController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});

Route::prefix('admin')->name('admin.')->middleware('auth')->group(function () {
    Route::get('/', fn () => redirect()->route('admin.posts.index'))->name('dashboard');
    Route::resource('posts', PostController::class)->except('show');
    Route::post('/posts/{post}/revisions/{revision}/restore', [PostController::class, 'restoreRevision'])->name('posts.revisions.restore');
    Route::resource('pages', PostController::class)->parameters(['pages' => 'post'])->except('show');
    Route::post('/pages/{post}/revisions/{revision}/restore', [PostController::class, 'restoreRevision'])->name('pages.revisions.restore');
    Route::resource('categories', CategoryController::class)->except(['create', 'show']);
    Route::resource('comments', CommentController::class)->only(['index', 'update', 'destroy']);
    Route::get('/media', [MediaController::class, 'index'])->name('media.index');
    Route::post('/media', [MediaController::class, 'store'])->name('media.store');
    Route::get('/wordpress-import', [WordPressImportController::class, 'create'])->name('wordpress-import.create');
    Route::post('/wordpress-import', [WordPressImportController::class, 'store'])->name('wordpress-import.store');
});

Route::redirect('/', '/admin');
