<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SsoLoginController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use Illuminate\Support\Facades\Route;
use Modules\Platform\Identity\Http\Controllers\IdentityRoleController;
use Modules\Platform\Identity\Http\Controllers\ImpersonationController;
use Modules\Platform\Identity\Http\Controllers\PasskeyController;
use Modules\Platform\Identity\Http\Controllers\UserSessionAdminController;

/*
| Mod 004 IAM. Every route is decided by a permission key through the `can:` middleware (deny by default); the own
| permission snapshot is open to any signed-in member.
*/
Route::prefix('api/v1')->middleware(['api', 'locale'])->name('api.v1.passkeys.')->group(function (): void {
    Route::post('/auth/passkeys/login/options', [PasskeyController::class, 'loginOptions'])->middleware('throttle:login')->name('login.options');
    Route::post('/auth/passkeys/login', [PasskeyController::class, 'login'])->middleware('throttle:login')->name('login');

    // Spec paths (Mod 004) of the existing second-factor challenge and OIDC sign-in.
    Route::post('/auth/2fa/challenge', [AuthController::class, 'twoFactorChallenge'])->middleware('throttle:two-factor-challenge')->name('2fa.challenge');
    Route::get('/auth/sso/{provider}/redirect', [SsoLoginController::class, 'redirect'])->middleware('throttle:20,1')->name('sso.redirect');
    Route::match(['get', 'post'], '/auth/sso/{provider}/callback', [SsoLoginController::class, 'callback'])->middleware('throttle:20,1')->name('sso.callback');
});

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])
    ->name('api.v1.iam.')
    ->group(function (): void {
        Route::get('/auth/passkeys', [PasskeyController::class, 'index'])->name('passkeys.index');
        Route::post('/auth/passkeys/options', [PasskeyController::class, 'registrationOptions'])->middleware('throttle:6,1')->name('passkeys.options');
        Route::post('/auth/passkeys', [PasskeyController::class, 'register'])->middleware('throttle:6,1')->name('passkeys.store');
        Route::patch('/auth/passkeys/{id}', [PasskeyController::class, 'rename'])->whereUuid('id')->middleware('throttle:20,1')->name('passkeys.rename');
        Route::delete('/auth/passkeys/{id}', [PasskeyController::class, 'destroy'])->whereUuid('id')->name('passkeys.destroy');

        Route::post('/auth/2fa/totp', [TwoFactorController::class, 'enable'])->middleware('throttle:6,1')->name('2fa.totp.enable');
        Route::post('/auth/2fa/totp/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:6,1')->name('2fa.totp.confirm');
        Route::delete('/auth/2fa/totp', [TwoFactorController::class, 'disable'])->middleware('throttle:6,1')->name('2fa.totp.disable');

        Route::get('/profile/sessions', [SessionController::class, 'index'])->name('profile.sessions.index');
        Route::delete('/profile/sessions/{id}', [SessionController::class, 'destroyAny'])->where('id', '[A-Za-z0-9]{1,64}')->name('profile.sessions.destroy');

        Route::get('/profile/permissions', [IdentityRoleController::class, 'profilePermissions'])->name('profile.permissions');

        Route::prefix('settings')->group(function (): void {
            Route::get('/permissions/catalog', [IdentityRoleController::class, 'catalog'])->middleware('can:settings.roles.view')->name('catalog');

            Route::get('/roles', [IdentityRoleController::class, 'index'])->middleware('can:settings.roles.view')->name('roles.index');
            Route::post('/roles', [IdentityRoleController::class, 'store'])->middleware('can:settings.roles.create')->name('roles.store');
            Route::get('/roles/{role}', [IdentityRoleController::class, 'show'])->whereUuid('role')->middleware('can:settings.roles.view')->name('roles.show');
            Route::put('/roles/{role}', [IdentityRoleController::class, 'update'])->whereUuid('role')->middleware('can:settings.roles.update')->name('roles.update');
            Route::delete('/roles/{role}', [IdentityRoleController::class, 'destroy'])->whereUuid('role')->middleware('can:settings.roles.delete')->name('roles.destroy');
            Route::post('/roles/{role}/clone', [IdentityRoleController::class, 'clone'])->whereUuid('role')->middleware('can:settings.roles.create')->name('roles.clone');
            Route::get('/roles/{role}/abac-rules', [IdentityRoleController::class, 'abacRules'])->whereUuid('role')->middleware('can:settings.roles.view')->name('roles.abac.show');
            Route::put('/roles/{role}/abac-rules', [IdentityRoleController::class, 'updateAbacRules'])->whereUuid('role')->middleware('can:settings.roles.update')->name('roles.abac.update');
            Route::get('/roles/{role}/field-policies', [IdentityRoleController::class, 'fieldPolicies'])->whereUuid('role')->middleware('can:settings.roles.view')->name('roles.fields.show');
            Route::put('/roles/{role}/field-policies', [IdentityRoleController::class, 'updateFieldPolicies'])->whereUuid('role')->middleware('can:settings.roles.update')->name('roles.fields.update');

            Route::post('/users/{user}/revoke-sessions', [UserSessionAdminController::class, 'revoke'])->whereUuid('user')->middleware('can:settings.users.update')->name('users.revoke-sessions');
            Route::post('/users/{user}/impersonate', [ImpersonationController::class, 'start'])->whereUuid('user')->middleware(['can:settings.users.update', 'throttle:6,1'])->name('users.impersonate');
            Route::delete('/users/{user}/impersonate', [ImpersonationController::class, 'end'])->whereUuid('user')->middleware('can:settings.users.update')->name('users.impersonate.end');
            Route::get('/access-review', [ImpersonationController::class, 'accessReview'])->middleware('can:settings.users.view')->name('access-review');
            Route::put('/users/{user}/roles', [IdentityRoleController::class, 'assign'])->whereUuid('user')->middleware('can:settings.users.update')->name('users.roles');
        });
    });
