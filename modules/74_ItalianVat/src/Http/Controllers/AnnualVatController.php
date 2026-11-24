<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\ItalianVat\Http\Controllers\Concerns\ResolvesItalianVatContext;
use Modules\ItalianVat\Http\Requests\EmptyActionRequest;
use Modules\ItalianVat\Http\Resources\AnnualVatReconciliationResource;
use Modules\ItalianVat\Services\AnnualVatReconciliationService;
use OpenApi\Attributes as OA;

/**
 * The annual VAT reconciliation.
 */
final class AnnualVatController extends Controller
{
    use ApiResponse;
    use ResolvesItalianVatContext;

    public function __construct(private readonly AnnualVatReconciliationService $annual) {}

    #[OA\Get(path: '/api/v1/italian-vat/annual-vat/{year}', operationId: 'getItalianVatAnnualReconciliation', summary: 'The annual VAT reconciliation of a year', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'The reconciliation'), new OA\Response(response: 404, description: 'Not calculated yet')])]
    public function show(string $year): JsonResponse
    {
        return $this->successResponse(new AnnualVatReconciliationResource($this->annual->find($this->tenantId(), (int) $year)));
    }

    #[OA\Post(path: '/api/v1/italian-vat/annual-vat/{year}/calculate', operationId: 'calculateItalianVatAnnualReconciliation', summary: 'Calculate the annual VAT reconciliation', description: 'Adds up the periodic settlements, adjusts the deducted VAT with the definitive pro-rata, applies the credit of the previous year and the payments, and compares the result with the Erario c/IVA account.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'The reconciliation with its diagnostics'), new OA\Response(response: 409, description: 'The year is finalized')])]
    public function calculate(EmptyActionRequest $request, string $year): JsonResponse
    {
        return $this->successResponse(new AnnualVatReconciliationResource($this->annual->calculate($this->tenantId(), (int) $year)), 'Annual VAT calculated');
    }

    #[OA\Post(path: '/api/v1/italian-vat/annual-vat/{year}/finalize', operationId: 'finalizeItalianVatAnnualReconciliation', summary: 'Finalize the annual VAT reconciliation', description: 'Only a reconciled year can be finalized.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'The finalized reconciliation'), new OA\Response(response: 422, description: 'The year does not reconcile')])]
    public function finalize(EmptyActionRequest $request, string $year): JsonResponse
    {
        return $this->successResponse(new AnnualVatReconciliationResource($this->annual->finalize($this->tenantId(), $this->userId($request), (int) $year)), 'Annual VAT finalized');
    }
}
