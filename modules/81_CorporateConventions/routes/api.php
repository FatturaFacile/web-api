<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CorporateConventions\Http\Controllers\AnalyticsController;
use Modules\CorporateConventions\Http\Controllers\ConventionController;
use Modules\CorporateConventions\Http\Controllers\EnrollmentController;
use Modules\CorporateConventions\Http\Controllers\LookupController;
use Modules\CorporateConventions\Http\Controllers\PosController;
use Modules\CorporateConventions\Http\Controllers\PublicConventionController;
use Modules\CorporateConventions\Http\Controllers\SplitBillingController;
use Modules\CorporateConventions\Http\Controllers\WelfareVoucherController;

/*
|--------------------------------------------------------------------------
| Corporate conventions, partner employee discounts and B2B welfare cards (Mod 358 / Issue #604)
|--------------------------------------------------------------------------
| Back office and till (authenticated, per company): conventions with eligibility rules and discount matrix, beneficiaries and
| wallet passes, cart pricing and checkout with split billing, the consolidated B2B invoice of the employer quota, welfare vouchers
| (IT Art. 51 TUIR, CH Reka / Lunch-Check) with their settlement, and the ROI dashboard.
| Public self-service (no account, rate limited): the landing page /convenzioni/<slug> verifies an employee by work e-mail OTP or
| card / matricola and hands out the pass.
*/
Route::prefix('api/v1/sales')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:corporate_conventions'])
    ->group(function (): void {
        Route::get('corporate-conventions/lookups', [LookupController::class, 'lookups'])->name('corporate-conventions.lookups');

        Route::get('corporate-conventions/analytics/roi', [AnalyticsController::class, 'roi'])->name('corporate-conventions.analytics.roi');
        Route::get('corporate-conventions/analytics/roi/export', [AnalyticsController::class, 'export'])->name('corporate-conventions.analytics.roi.export');

        Route::post('corporate-conventions/pos/pricing', [PosController::class, 'pricing'])->name('corporate-conventions.pos.pricing');
        Route::post('corporate-conventions/pos/checkout', [PosController::class, 'checkout'])->name('corporate-conventions.pos.checkout');

        Route::get('corporate-conventions/enrollments', [EnrollmentController::class, 'index'])->name('corporate-conventions.enrollments.index');
        Route::post('corporate-conventions/enrollments/verify', [EnrollmentController::class, 'verify'])->name('corporate-conventions.enrollments.verify');
        Route::get('corporate-conventions/enrollments/{id}', [EnrollmentController::class, 'show'])->whereUuid('id')->name('corporate-conventions.enrollments.show');
        Route::post('corporate-conventions/enrollments/{id}/family-members', [EnrollmentController::class, 'addFamilyMember'])->whereUuid('id')->name('corporate-conventions.enrollments.family');
        Route::post('corporate-conventions/enrollments/{id}/revoke', [EnrollmentController::class, 'revoke'])->whereUuid('id')->name('corporate-conventions.enrollments.revoke');
        Route::get('corporate-conventions/enrollments/{id}/qr', [EnrollmentController::class, 'qr'])->whereUuid('id')->name('corporate-conventions.enrollments.qr');

        Route::get('corporate-conventions', [ConventionController::class, 'index'])->name('corporate-conventions.conventions.index');
        Route::post('corporate-conventions', [ConventionController::class, 'store'])->name('corporate-conventions.conventions.store');
        Route::get('corporate-conventions/{id}', [ConventionController::class, 'show'])->whereUuid('id')->name('corporate-conventions.conventions.show');
        Route::put('corporate-conventions/{id}', [ConventionController::class, 'update'])->whereUuid('id')->name('corporate-conventions.conventions.update');
        Route::delete('corporate-conventions/{id}', [ConventionController::class, 'destroy'])->whereUuid('id')->name('corporate-conventions.conventions.destroy');
        Route::post('corporate-conventions/{id}/transition', [ConventionController::class, 'transition'])->whereUuid('id')->name('corporate-conventions.conventions.transition');
        Route::post('corporate-conventions/{id}/identifiers', [ConventionController::class, 'importIdentifiers'])->whereUuid('id')->name('corporate-conventions.conventions.identifiers');
        Route::get('corporate-conventions/{id}/identifiers/stats', [ConventionController::class, 'identifierStats'])->whereUuid('id')->name('corporate-conventions.conventions.identifier-stats');
        Route::post('corporate-conventions/{id}/contract', [ConventionController::class, 'uploadContract'])->whereUuid('id')->name('corporate-conventions.conventions.contract');
        Route::get('corporate-conventions/{id}/contract', [ConventionController::class, 'downloadContract'])->whereUuid('id')->name('corporate-conventions.conventions.contract.download');
        Route::post('corporate-conventions/{id}/logo', [ConventionController::class, 'uploadLogo'])->whereUuid('id')->name('corporate-conventions.conventions.logo');

        Route::get('split-billings', [SplitBillingController::class, 'index'])->name('corporate-conventions.split-billings.index');
        Route::get('split-billings/pending', [SplitBillingController::class, 'pending'])->name('corporate-conventions.split-billings.pending');
        Route::post('split-billings/preview', [SplitBillingController::class, 'preview'])->name('corporate-conventions.split-billings.preview');
        Route::post('split-billings/generate-invoice', [SplitBillingController::class, 'generateInvoice'])->name('corporate-conventions.split-billings.generate');
        Route::get('split-billings/invoices/{invoiceId}/attachment', [SplitBillingController::class, 'attachment'])->whereUuid('invoiceId')->name('corporate-conventions.split-billings.attachment');

        Route::get('welfare-vouchers', [WelfareVoucherController::class, 'index'])->name('corporate-conventions.welfare.index');
        Route::get('welfare-vouchers/summary', [WelfareVoucherController::class, 'summary'])->name('corporate-conventions.welfare.summary');
        Route::post('welfare-vouchers/redeem', [WelfareVoucherController::class, 'redeem'])->name('corporate-conventions.welfare.redeem');
        Route::post('welfare-vouchers/submit-batch', [WelfareVoucherController::class, 'submitBatch'])->name('corporate-conventions.welfare.submit-batch');
        Route::post('welfare-vouchers/reconcile', [WelfareVoucherController::class, 'reconcile'])->name('corporate-conventions.welfare.reconcile');
    });

// Public landing page of a convention: no account, the slug of the convention selects the company and the unguessable wallet
// pass id is the credential of a pass. Rate limited per IP; the code request has its own, stricter limit.
Route::prefix('api/v1/public/conventions')
    ->middleware(['api', 'locale', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('passes/{walletPassId}', [PublicConventionController::class, 'pass'])->whereUuid('walletPassId')->name('corporate-conventions.public.pass');
        Route::post('passes/{walletPassId}/family-members', [PublicConventionController::class, 'addFamilyMember'])->whereUuid('walletPassId')->middleware('throttle:10,1')->name('corporate-conventions.public.family');
        Route::get('passes/{walletPassId}/qr.svg', [PublicConventionController::class, 'qrSvg'])->whereUuid('walletPassId')->name('corporate-conventions.public.qr-svg');
        Route::get('passes/{walletPassId}/qr.png', [PublicConventionController::class, 'qrPng'])->whereUuid('walletPassId')->name('corporate-conventions.public.qr-png');
        Route::get('passes/{walletPassId}/apple.pkpass', [PublicConventionController::class, 'applePass'])->whereUuid('walletPassId')->name('corporate-conventions.public.apple');

        Route::get('{slug}', [PublicConventionController::class, 'show'])->name('corporate-conventions.public.show');
        Route::get('{slug}/logo', [PublicConventionController::class, 'logo'])->name('corporate-conventions.public.logo');
        Route::post('{slug}/request-otp', [PublicConventionController::class, 'requestOtp'])->middleware('throttle:5,1')->name('corporate-conventions.public.request-otp');
        Route::post('{slug}/verify-otp', [PublicConventionController::class, 'verifyOtp'])->middleware('throttle:10,1')->name('corporate-conventions.public.verify-otp');
        Route::post('{slug}/verify-identifier', [PublicConventionController::class, 'verifyIdentifier'])->middleware('throttle:10,1')->name('corporate-conventions.public.verify-identifier');
    });
