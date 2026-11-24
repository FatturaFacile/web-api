<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\ItalianVat\Http\Controllers\Concerns\ResolvesItalianVatContext;
use Modules\ItalianVat\Http\Requests\PlafondCheckRequest;
use Modules\ItalianVat\Http\Requests\PlafondMonitorRequest;
use Modules\ItalianVat\Http\Requests\RevokeDeclarationRequest;
use Modules\ItalianVat\Services\PlafondGuardService;
use OpenApi\Attributes as OA;

/**
 * The plafond of the habitual exporters and the declarations of intent (art. 8 c. 1 lett. c DPR 633/1972).
 */
final class PlafondController extends Controller
{
    use ApiResponse;
    use ResolvesItalianVatContext;

    public function __construct(private readonly PlafondGuardService $guard) {}

    #[OA\Post(path: '/api/v1/italian-vat/plafond/check', operationId: 'checkItalianVatPlafond', summary: 'Check a sale without VAT against the plafond', description: 'Tells, before an order or an invoice is issued, whether a declaration of intent of the customer is valid on the date, not revoked and has the plafond left. Nothing is consumed: the plafond is consumed when the invoice is issued.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], responses: [new OA\Response(response: 200, description: 'The assessment, with the reason when the sale is not covered')])]
    public function check(PlafondCheckRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse($this->guard->assess($this->tenantId(), (string) $data['contact_id'], (string) $data['date'], (string) $data['amount'], $data['declaration_id'] ?? null));
    }

    #[OA\Get(path: '/api/v1/italian-vat/plafond/monitor', operationId: 'monitorItalianVatPlafond', summary: 'Erosion of the plafond of the declarations received', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))], responses: [new OA\Response(response: 200, description: 'Plafond, consumption and alert of each declaration')])]
    public function monitor(PlafondMonitorRequest $request): JsonResponse
    {
        $asOf = $request->validated('as_of');

        return $this->successResponse($this->guard->monitor($this->tenantId(), (int) $request->validated('year'), $asOf === null ? null : CarbonImmutable::parse((string) $asOf)));
    }

    #[OA\Post(path: '/api/v1/italian-vat/plafond/declarations/{id}/revoke', operationId: 'revokeItalianVatDeclarationOfIntent', summary: 'Revoke a declaration of intent', description: 'A revoked declaration covers no new sale; the sales already issued keep their consumption.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The revoked declaration'), new OA\Response(response: 404, description: 'Unknown declaration'), new OA\Response(response: 409, description: 'Already revoked')])]
    public function revoke(RevokeDeclarationRequest $request, string $id): JsonResponse
    {
        $declaration = $this->guard->revoke($this->tenantId(), $id, $request->validated('reason'));

        return $this->successResponse([
            'id' => (string) $declaration->id,
            'protocol_number' => $declaration->protocol_number,
            'revoked_at' => $declaration->revoked_at?->toIso8601String(),
            'revocation_reason' => $declaration->revocation_reason,
        ], 'Declaration revoked');
    }
}
