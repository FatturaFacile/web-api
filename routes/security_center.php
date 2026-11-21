<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\SecurityCenterController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Security, Privacy & Compliance Center
|--------------------------------------------------------------------------
|
| Included by routes/api.php inside the authenticated tenant group.
|
*/

Route::prefix('security')->name('security.')->group(function (): void {
    Route::get('/posture', [SecurityCenterController::class, 'posture'])->middleware(['audit.permission:audit.view', 'throttle:20,1'])->name('posture');
    Route::post('/pii/unmask', [SecurityCenterController::class, 'unmask'])->middleware(['audit.permission:privacy.pii.unmask', 'throttle:30,1'])->name('pii.unmask');
});
