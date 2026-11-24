<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tax;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tax\EnasarcoStatusRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Services\Invoicing\Tax\EnasarcoService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * ENASARCO year status towards one principal: commissions invoiced, ceiling, remaining ceiling and minimum shortfall.
 */
class EnasarcoStatusController extends Controller
{
    use ApiResponse, AuthorizesRequests;

    public function __construct(private readonly EnasarcoService $enasarco) {}

    #[OA\Get(
        path: '/api/v1/tax/enasarco/status',
        operationId: 'getEnasarcoStatus',
        description: 'Year-to-date commissions invoiced to a principal, annual commission ceiling, remaining ceiling, annual minimum and shortfall (minimum minus contributions withheld).',
        summary: 'ENASARCO status',
        security: [['bearerAuth' => []]],
        tags: ['Tax'],
        parameters: [
            new OA\Parameter(name: 'contact_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 2026)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Status retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error or no ENASARCO rate for the year (code enasarco_rate_not_configured)', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function __invoke(EnasarcoStatusRequest $request): JsonResponse
    {
        $this->authorize('viewTaxReports', Invoice::class);

        return $this->successResponse(
            $this->enasarco->status((string) $request->input('contact_id'), $request->integer('year')),
            __('tax.messages.enasarco_status'),
        );
    }
}
