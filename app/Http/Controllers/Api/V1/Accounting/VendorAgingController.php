<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\VendorAgingRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Accounting\VendorAgingReportService;
use Illuminate\Http\JsonResponse;

/**
 * Open supplier balances by supplier and due-date bucket, with the expected cash outflow.
 */
class VendorAgingController extends Controller
{
    use ApiResponse;

    public function __invoke(VendorAgingRequest $request, VendorAgingReportService $aging): JsonResponse
    {
        return $this->successResponse($aging->report($request->asOf(), $request->supplierId()), __('accounting.messages.vendor_aging_retrieved'));
    }
}
