<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\SamlLoginController;
use App\Http\Controllers\Api\V1\SamlProviderController;
use App\Http\Controllers\Api\V1\Scim\ScimDiscoveryController;
use App\Http\Controllers\Api\V1\Scim\ScimGroupController;
use App\Http\Controllers\Api\V1\Scim\ScimUserController;
use App\Http\Controllers\Api\V1\ScimGroupSettingsController;
use App\Http\Controllers\Api\V1\ScimTokenController;
use App\Http\Controllers\Api\V1\SecurityAuditController;
use App\Http\Controllers\Api\V1\SecurityPolicyController;
use App\Http\Controllers\Api\V1\SsoDiscoveryController;
use App\Http\Controllers\Api\V1\SsoLoginController;
use App\Http\Controllers\Api\V1\SsoProviderController;
use App\Http\Middleware\AuthenticateScim;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Enterprise authentication
|--------------------------------------------------------------------------
|
| Included by routes/api.php inside the `v1` group. OpenID Connect single sign-on (public start and callback), the
| tenant security policy, SSO provider and SCIM token settings (owners and administrators, module `system`), and the
| SCIM 2.0 Users endpoints (authenticated by the SCIM bearer token of a tenant, not by a user token).
|
*/

Route::prefix('auth/sso')->middleware('locale')->name('auth.sso.')->group(function (): void {
    Route::post('/discover', [SsoDiscoveryController::class, 'discover'])->middleware('throttle:20,1')->name('discover');
    Route::post('/start', [SsoLoginController::class, 'start'])->middleware('throttle:20,1')->name('start');
    Route::post('/callback', [SsoLoginController::class, 'callback'])->middleware('throttle:20,1')->name('callback');
    Route::post('/exchange', [SsoLoginController::class, 'exchange'])->middleware('throttle:20,1')->name('exchange');
});

Route::prefix('auth/saml')->middleware('locale')->name('auth.saml.')->whereUuid('tenantId')->group(function (): void {
    Route::post('/start', [SamlLoginController::class, 'start'])->middleware('throttle:20,1')->name('start');
    Route::get('/{tenantId}/metadata', [SamlLoginController::class, 'metadata'])->middleware('throttle:60,1')->name('metadata');
    Route::post('/{tenantId}/acs', [SamlLoginController::class, 'acs'])->middleware('throttle:20,1')->name('acs');
    Route::get('/{tenantId}/slo', [SamlLoginController::class, 'slo'])->middleware('throttle:20,1')->name('slo');
});

Route::middleware(['auth:sanctum', 'tenant', 'locale', 'module.access:system'])
    ->prefix('settings/security')
    ->name('settings.security.')
    ->group(function (): void {
        Route::get('/policy', [SecurityPolicyController::class, 'show'])->name('policy');
        Route::put('/policy', [SecurityPolicyController::class, 'update'])->name('policy.update');

        Route::get('/sso', [SsoProviderController::class, 'show'])->name('sso');
        Route::put('/sso', [SsoProviderController::class, 'upsert'])->name('sso.update');
        Route::post('/sso/discovery', [SsoProviderController::class, 'refreshDiscovery'])->middleware('throttle:10,1')->name('sso.discovery');
        Route::delete('/sso', [SsoProviderController::class, 'destroy'])->name('sso.destroy');

        Route::get('/saml', [SamlProviderController::class, 'show'])->name('saml');
        Route::put('/saml', [SamlProviderController::class, 'upsert'])->name('saml.update');
        Route::post('/saml/metadata', [SamlProviderController::class, 'refreshMetadata'])->middleware('throttle:10,1')->name('saml.metadata');
        Route::delete('/saml', [SamlProviderController::class, 'destroy'])->name('saml.destroy');

        Route::get('/scim', [ScimTokenController::class, 'show'])->name('scim');
        Route::post('/scim/token', [ScimTokenController::class, 'rotate'])->middleware('throttle:6,1')->name('scim.rotate');
        Route::delete('/scim/token', [ScimTokenController::class, 'revoke'])->name('scim.revoke');

        Route::get('/scim/group-mappings', [ScimGroupSettingsController::class, 'index'])->name('scim.group-mappings');
        Route::put('/scim/group-mappings', [ScimGroupSettingsController::class, 'upsert'])->middleware('throttle:30,1')->name('scim.group-mappings.upsert');
        Route::delete('/scim/group-mappings/{id}', [ScimGroupSettingsController::class, 'destroy'])->whereUuid('id')->middleware('throttle:30,1')->name('scim.group-mappings.destroy');
        Route::get('/scim/logs', [ScimGroupSettingsController::class, 'logs'])->name('scim.logs');

        Route::get('/audit', [SecurityAuditController::class, 'index'])->name('audit');
        Route::get('/audit/event-types', [SecurityAuditController::class, 'eventTypes'])->name('audit.event-types');
    });

Route::prefix('scim/v2')
    ->middleware(['throttle:300,1', AuthenticateScim::class])
    ->name('scim.')
    ->group(function (): void {
        Route::get('/ServiceProviderConfig', [ScimDiscoveryController::class, 'serviceProviderConfig'])->name('service-provider-config');
        Route::get('/ResourceTypes', [ScimDiscoveryController::class, 'resourceTypes'])->name('resource-types');
        Route::get('/Schemas', [ScimDiscoveryController::class, 'schemas'])->name('schemas');
        Route::get('/Schemas/{id}', [ScimDiscoveryController::class, 'schema'])->name('schemas.show');
        Route::get('/Users', [ScimUserController::class, 'index'])->name('users.index');
        Route::post('/Users', [ScimUserController::class, 'store'])->name('users.store');
        Route::get('/Users/{id}', [ScimUserController::class, 'show'])->name('users.show');
        Route::put('/Users/{id}', [ScimUserController::class, 'replace'])->name('users.replace');
        Route::patch('/Users/{id}', [ScimUserController::class, 'update'])->name('users.update');
        Route::delete('/Users/{id}', [ScimUserController::class, 'destroy'])->name('users.destroy');
        Route::get('/Groups', [ScimGroupController::class, 'index'])->name('groups.index');
        Route::post('/Groups', [ScimGroupController::class, 'store'])->name('groups.store');
        Route::get('/Groups/{id}', [ScimGroupController::class, 'show'])->name('groups.show');
        Route::put('/Groups/{id}', [ScimGroupController::class, 'replace'])->name('groups.replace');
        Route::patch('/Groups/{id}', [ScimGroupController::class, 'update'])->name('groups.update');
        Route::delete('/Groups/{id}', [ScimGroupController::class, 'destroy'])->name('groups.destroy');
    });

// Advanced second factors: email code, security key challenge, trusted devices.
require __DIR__.'/advanced_mfa.php';
