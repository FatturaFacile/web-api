<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Communication\Http\Controllers\CommunicationController;
use Modules\Communication\Http\Controllers\MessageController;
use Modules\Communication\Http\Controllers\TemplateController;

Route::prefix('api/v1/communication')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:communication'])
    ->group(function (): void {
        Route::post('documents/send', [CommunicationController::class, 'send'])->name('communication.documents.send');
        Route::post('messages/send', [MessageController::class, 'send'])->name('communication.messages.send');
        Route::get('deliveries', [CommunicationController::class, 'deliveries'])->name('communication.deliveries');
        Route::get('deliveries/{id}', [CommunicationController::class, 'showDelivery'])->whereUuid('id')->name('communication.deliveries.show');

        Route::get('templates', [TemplateController::class, 'index'])->name('communication.templates.index');
        Route::post('templates', [TemplateController::class, 'store'])->name('communication.templates.store');
        Route::get('templates/{id}', [TemplateController::class, 'show'])->whereUuid('id')->name('communication.templates.show');
        Route::put('templates/{id}', [TemplateController::class, 'update'])->whereUuid('id')->name('communication.templates.update');
        Route::delete('templates/{id}', [TemplateController::class, 'destroy'])->whereUuid('id')->name('communication.templates.destroy');
        Route::post('templates/{id}/preview', [TemplateController::class, 'preview'])->whereUuid('id')->name('communication.templates.preview');
    });

// Channel settings hold server addresses and secrets: owners and administrators only (module key integrations).
Route::prefix('api/v1/communication/channels')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:integrations'])
    ->group(function (): void {
        Route::get('/', [CommunicationController::class, 'channels'])->name('communication.channels.index');
        Route::put('{channel}', [CommunicationController::class, 'updateChannel'])->whereIn('channel', ['email', 'whatsapp', 'telegram', 'sms'])->name('communication.channels.update');
        Route::post('{channel}/test', [MessageController::class, 'testChannel'])->whereIn('channel', ['email', 'whatsapp', 'telegram', 'sms'])->name('communication.channels.test');
    });
