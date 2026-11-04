<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\VendorAgingRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Accounting\VendorAgingReportService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Open supplier balances by supplier and due-date bucket, with the expected cash outflow.
 */
class VendorAgingController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/accounting/vendor-aging',
        operationId: 'getVendorAgingReport',
        description: 'Get open supplier balances by due-date aging buckets with expected cash outflow schedules.',
        summary: 'Vendor aging report',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Accounting', 'AP'],
        parameters: [
            new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'supplier_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Vendor aging report generated successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function __invoke(VendorAgingRequest $request, VendorAgingReportService $aging): JsonResponse
    {
        return $this->successResponse($aging->report($request->asOf(), $request->supplierId()), __('accounting.messages.vendor_aging_retrieved'));
    }
}
