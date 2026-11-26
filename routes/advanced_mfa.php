<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\TrustedDeviceController;
use App\Http\Controllers\Api\V1\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\TwoFactorEmailController;
use App\Http\Controllers\Api\V1\TwoFactorSmsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Advanced second factors
|--------------------------------------------------------------------------
|
| Included by routes/enterprise_auth.php inside the `v1` group. The helpers of the login challenge (send the email code,
| security key options) are public and named by the challenge token; the enrolment of the email factor and the trusted
| devices belong to the signed-in user. Route names start with `auth.`, so a member whose tenant requires two factors
| can reach them before the setup is complete.
|
*/

Route::prefix('auth/two-factor/challenge')->middleware(['locale', 'throttle:two-factor-challenge'])->name('auth.two-factor.challenge.')->group(function (): void {
    Route::post('/email', [TwoFactorChallengeController::class, 'sendEmail'])->name('email');
    Route::post('/sms', [TwoFactorChallengeController::class, 'sendSms'])->name('sms');
    Route::post('/webauthn/options', [TwoFactorChallengeController::class, 'webauthnOptions'])->name('webauthn.options');
});

Route::middleware(['auth:sanctum', 'mobile.device', 'tenant', 'locale'])->prefix('auth/two-factor')->name('auth.two-factor.')->group(function (): void {
    Route::post('/email/enable', [TwoFactorEmailController::class, 'enable'])->middleware('throttle:6,1')->name('email.enable');
    Route::post('/email/confirm', [TwoFactorEmailController::class, 'confirm'])->middleware('throttle:6,1')->name('email.confirm');
    Route::post('/email/send', [TwoFactorEmailController::class, 'send'])->middleware('throttle:6,1')->name('email.send');
    Route::delete('/email', [TwoFactorEmailController::class, 'destroy'])->middleware('throttle:6,1')->name('email.destroy');

    Route::post('/sms/enable', [TwoFactorSmsController::class, 'enable'])->middleware('throttle:6,1')->name('sms.enable');
    Route::post('/sms/confirm', [TwoFactorSmsController::class, 'confirm'])->middleware('throttle:6,1')->name('sms.confirm');
    Route::post('/sms/send', [TwoFactorSmsController::class, 'send'])->middleware('throttle:6,1')->name('sms.send');
    Route::delete('/sms', [TwoFactorSmsController::class, 'destroy'])->middleware('throttle:6,1')->name('sms.destroy');

    Route::get('/trusted-devices', [TrustedDeviceController::class, 'index'])->name('trusted-devices.index');
    Route::delete('/trusted-devices', [TrustedDeviceController::class, 'destroyAll'])->name('trusted-devices.destroy-all');
    Route::delete('/trusted-devices/{id}', [TrustedDeviceController::class, 'destroy'])->whereUuid('id')->name('trusted-devices.destroy');
});
