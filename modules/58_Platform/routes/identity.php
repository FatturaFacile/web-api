<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Platform\Identity\Http\Controllers\IdentityRoleController;
use Modules\Platform\Identity\Http\Controllers\PasskeyController;
use Modules\Platform\Identity\Http\Controllers\UserSessionAdminController;

/*
| Mod 004 IAM. Every route is decided by a permission key through the `can:` middleware (deny by default); the own
| permission snapshot is open to any signed-in member.
*/
Route::prefix('api/v1')->middleware(['api', 'locale'])->name('api.v1.passkeys.')->group(function (): void {
    Route::post('/auth/passkeys/login/options', [PasskeyController::class, 'loginOptions'])->middleware('throttle:login')->name('login.options');
    Route::post('/auth/passkeys/login', [PasskeyController::class, 'login'])->middleware('throttle:login')->name('login');
});

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])
    ->name('api.v1.iam.')
    ->group(function (): void {
        Route::get('/auth/passkeys', [PasskeyController::class, 'index'])->name('passkeys.index');
        Route::post('/auth/passkeys/options', [PasskeyController::class, 'registrationOptions'])->middleware('throttle:6,1')->name('passkeys.options');
        Route::post('/auth/passkeys', [PasskeyController::class, 'register'])->middleware('throttle:6,1')->name('passkeys.store');
        Route::delete('/auth/passkeys/{id}', [PasskeyController::class, 'destroy'])->whereUuid('id')->name('passkeys.destroy');

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
            Route::put('/users/{user}/roles', [IdentityRoleController::class, 'assign'])->whereUuid('user')->middleware('can:settings.users.update')->name('users.roles');
        });
    });
