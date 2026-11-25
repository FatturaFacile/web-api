<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ElectronicSignature\Http\Controllers\EnvelopeController;
use Modules\ElectronicSignature\Http\Controllers\LookupController;
use Modules\ElectronicSignature\Http\Controllers\ProviderConfigController;
use Modules\ElectronicSignature\Http\Controllers\PublicSigningController;
use Modules\ElectronicSignature\Http\Controllers\PublicVerificationController;
use Modules\ElectronicSignature\Http\Controllers\SignatureWebhookController;
use Modules\ElectronicSignature\Http\Controllers\TemplateController;

/*
|--------------------------------------------------------------------------
| Electronic signatures: FES / FEA / FEQ under eIDAS and ZertES
|--------------------------------------------------------------------------
| Envelopes with single, sequential and parallel routing, a built-in engine and the providers Swisscom Trust Services,
| InfoCert and Namirial, a public signing portal and public verification, signed provider callbacks.
*/
Route::prefix('api/v1/signatures')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:electronic_signature'])
    ->group(function (): void {
        Route::get('lookups', [LookupController::class, 'index'])->name('signatures.lookups');
        Route::get('policy', [LookupController::class, 'policy'])->name('signatures.policy');
        Route::get('dashboard', [LookupController::class, 'dashboard'])->name('signatures.dashboard');

        Route::get('providers', [ProviderConfigController::class, 'index'])->name('signatures.providers.index');
        Route::post('providers', [ProviderConfigController::class, 'store'])->name('signatures.providers.store');
        Route::put('providers/{id}', [ProviderConfigController::class, 'update'])->whereUuid('id')->name('signatures.providers.update');
        Route::delete('providers/{id}', [ProviderConfigController::class, 'destroy'])->whereUuid('id')->name('signatures.providers.destroy');
        Route::post('providers/{id}/test', [ProviderConfigController::class, 'test'])->whereUuid('id')->name('signatures.providers.test');

        Route::get('templates', [TemplateController::class, 'index'])->name('signatures.templates.index');
        Route::post('templates', [TemplateController::class, 'store'])->name('signatures.templates.store');
        Route::put('templates/{id}', [TemplateController::class, 'update'])->whereUuid('id')->name('signatures.templates.update');
        Route::delete('templates/{id}', [TemplateController::class, 'destroy'])->whereUuid('id')->name('signatures.templates.destroy');

        Route::get('preview', [EnvelopeController::class, 'preview'])->name('signatures.preview');
        Route::get('envelopes', [EnvelopeController::class, 'index'])->name('signatures.envelopes.index');
        Route::post('envelopes', [EnvelopeController::class, 'store'])->name('signatures.envelopes.store');
        Route::get('envelopes/{id}', [EnvelopeController::class, 'show'])->whereUuid('id')->name('signatures.envelopes.show');
        Route::get('envelopes/{id}/audit', [EnvelopeController::class, 'audit'])->whereUuid('id')->name('signatures.envelopes.audit');
        Route::post('envelopes/{id}/send', [EnvelopeController::class, 'send'])->whereUuid('id')->name('signatures.envelopes.send');
        Route::post('envelopes/{id}/remind', [EnvelopeController::class, 'remind'])->whereUuid('id')->name('signatures.envelopes.remind');
        Route::post('envelopes/{id}/void', [EnvelopeController::class, 'void'])->whereUuid('id')->name('signatures.envelopes.void');
        Route::get('envelopes/{id}/download/{kind}', [EnvelopeController::class, 'download'])->whereUuid('id')->whereIn('kind', ['original', 'signed', 'certificate'])->name('signatures.envelopes.download');
    });

// Public signing portal: the unguessable token of the link is the credential (see PublicSigningController).
Route::prefix('api/v1/public/sign')
    ->middleware(['api', 'throttle:electronic-signature-portal'])
    ->group(function (): void {
        Route::get('{token}', [PublicSigningController::class, 'show'])->name('signatures.public.show');
        Route::get('{token}/document', [PublicSigningController::class, 'document'])->name('signatures.public.document');
        Route::post('{token}/challenge', [PublicSigningController::class, 'challenge'])->middleware('throttle:electronic-signature-challenge')->name('signatures.public.challenge');
        Route::post('{token}/sign', [PublicSigningController::class, 'sign'])->middleware('throttle:electronic-signature-challenge')->name('signatures.public.sign');
        Route::post('{token}/decline', [PublicSigningController::class, 'decline'])->name('signatures.public.decline');
    });

// Public verification: anybody with the signed file or its code can check it.
Route::prefix('api/v1/public/signatures')
    ->middleware(['api', 'throttle:electronic-signature-portal'])
    ->group(function (): void {
        Route::post('verify', [PublicVerificationController::class, 'verify'])->name('signatures.public.verify');
    });

// Provider callbacks: authenticated by the HMAC of the body, not by a session (see SignatureWebhookController).
Route::prefix('api/v1/webhooks/signatures')
    ->middleware(['api', 'throttle:120,1'])
    ->group(function (): void {
        Route::post('{provider}', [SignatureWebhookController::class, 'handle'])->where('provider', 'swisscom|infocert|namirial')->name('signatures.webhooks.handle');
    });
