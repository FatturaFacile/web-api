<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Communication\Http\Controllers\CommunicationController;

Route::prefix('api/v1/communication')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:communication'])
    ->group(function (): void {
        Route::post('documents/send', [CommunicationController::class, 'send'])->name('communication.documents.send');
        Route::get('deliveries', [CommunicationController::class, 'deliveries'])->name('communication.deliveries');
    });

Route::prefix('api/v1/communication/channels')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:integrations'])
    ->group(function (): void {
        Route::get('/', [CommunicationController::class, 'channels'])->name('communication.channels.index');
        Route::put('{channel}', [CommunicationController::class, 'updateChannel'])->whereIn('channel', ['email', 'whatsapp', 'telegram', 'sms'])->name('communication.channels.update');
    });
