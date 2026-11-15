<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Communication\Http\Controllers\CampaignController;
use Modules\Communication\Http\Controllers\CommunicationController;
use Modules\Communication\Http\Controllers\ContactPreferenceController;
use Modules\Communication\Http\Controllers\ConversationController;
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

        // Unified inbox
        Route::get('conversations', [ConversationController::class, 'index'])->name('communication.conversations.index');
        Route::post('conversations/inbound', [ConversationController::class, 'receive'])->name('communication.conversations.inbound');
        Route::get('conversations/{id}', [ConversationController::class, 'show'])->whereUuid('id')->name('communication.conversations.show');
        Route::post('conversations/{id}/reply', [ConversationController::class, 'reply'])->whereUuid('id')->name('communication.conversations.reply');
        Route::post('conversations/{id}/assign', [ConversationController::class, 'assign'])->whereUuid('id')->name('communication.conversations.assign');
        Route::post('conversations/{id}/status', [ConversationController::class, 'changeStatus'])->whereUuid('id')->name('communication.conversations.status');
        Route::post('conversations/{id}/read', [ConversationController::class, 'read'])->whereUuid('id')->name('communication.conversations.read');

        // Campaigns
        Route::get('campaigns', [CampaignController::class, 'index'])->name('communication.campaigns.index');
        Route::post('campaigns', [CampaignController::class, 'store'])->name('communication.campaigns.store');
        Route::get('campaigns/{id}', [CampaignController::class, 'show'])->whereUuid('id')->name('communication.campaigns.show');
        Route::put('campaigns/{id}', [CampaignController::class, 'update'])->whereUuid('id')->name('communication.campaigns.update');
        Route::delete('campaigns/{id}', [CampaignController::class, 'destroy'])->whereUuid('id')->name('communication.campaigns.destroy');
        Route::get('campaigns/{id}/preview', [CampaignController::class, 'preview'])->whereUuid('id')->name('communication.campaigns.preview');
        Route::get('campaigns/{id}/recipients', [CampaignController::class, 'recipients'])->whereUuid('id')->name('communication.campaigns.recipients');
        Route::post('campaigns/{id}/schedule', [CampaignController::class, 'schedule'])->whereUuid('id')->name('communication.campaigns.schedule');
        Route::post('campaigns/{id}/send', [CampaignController::class, 'send'])->whereUuid('id')->name('communication.campaigns.send');
        Route::post('campaigns/{id}/cancel', [CampaignController::class, 'cancel'])->whereUuid('id')->name('communication.campaigns.cancel');

        // Contact preferences and opt-out per channel
        Route::get('contacts/{id}/preferences', [ContactPreferenceController::class, 'show'])->whereUuid('id')->name('communication.contacts.preferences.show');
        Route::put('contacts/{id}/preferences', [ContactPreferenceController::class, 'update'])->whereUuid('id')->name('communication.contacts.preferences.update');
    });

// Channel settings hold server addresses and secrets: owners and administrators only (module key integrations).
Route::prefix('api/v1/communication/channels')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:integrations'])
    ->group(function (): void {
        Route::get('/', [CommunicationController::class, 'channels'])->name('communication.channels.index');
        Route::put('{channel}', [CommunicationController::class, 'updateChannel'])->whereIn('channel', ['email', 'whatsapp', 'telegram', 'sms'])->name('communication.channels.update');
        Route::post('{channel}/test', [MessageController::class, 'testChannel'])->whereIn('channel', ['email', 'whatsapp', 'telegram', 'sms'])->name('communication.channels.test');
    });
