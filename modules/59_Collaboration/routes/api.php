<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Collaboration\Http\Controllers\ActivityController;
use Modules\Collaboration\Http\Controllers\CommentController;
use Modules\Collaboration\Http\Controllers\SearchController;
use Modules\Collaboration\Http\Controllers\TagController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Cross-cutting collaboration: global search, comments with mentions, shared tags and the activity timeline of a record.
| module.access:collaboration lets every member read and write; the record type of the URL must be registered and its own
| module readable by the member (CollaborationRegistry), and a comment can only be edited by its author.
*/
Route::prefix('api/v1/collaboration')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:collaboration'])
    ->name('api.v1.collaboration.')
    ->where(['id' => $uuid, 'recordId' => $uuid, 'tagId' => $uuid, 'type' => '[a-z][a-z0-9_]*'])
    ->group(function (): void {
        Route::get('/search', [SearchController::class, 'index'])->name('search');
        Route::get('/record-types', [SearchController::class, 'recordTypes'])->name('record-types');
        Route::get('/mentionable-users', [SearchController::class, 'mentionableUsers'])->name('mentionable-users');

        Route::get('/records/{type}/{recordId}/comments', [CommentController::class, 'index'])->name('comments.index');
        Route::post('/records/{type}/{recordId}/comments', [CommentController::class, 'store'])->name('comments.store');
        Route::put('/comments/{id}', [CommentController::class, 'update'])->name('comments.update');
        Route::delete('/comments/{id}', [CommentController::class, 'destroy'])->name('comments.destroy');

        Route::get('/tags', [TagController::class, 'index'])->name('tags.index');
        Route::post('/tags', [TagController::class, 'store'])->name('tags.store');
        Route::put('/tags/{id}', [TagController::class, 'update'])->name('tags.update');
        Route::delete('/tags/{id}', [TagController::class, 'destroy'])->name('tags.destroy');
        Route::get('/tags/{id}/records', [TagController::class, 'records'])->name('tags.records');
        Route::get('/records/{type}/{recordId}/tags', [TagController::class, 'forRecord'])->name('records.tags.index');
        Route::post('/records/{type}/{recordId}/tags', [TagController::class, 'attach'])->name('records.tags.attach');
        Route::delete('/records/{type}/{recordId}/tags/{tagId}', [TagController::class, 'detach'])->name('records.tags.detach');

        Route::get('/records/{type}/{recordId}/activity', [ActivityController::class, 'index'])->name('activity.index');
    });
