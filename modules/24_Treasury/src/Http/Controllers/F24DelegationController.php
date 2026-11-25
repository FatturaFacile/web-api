<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Treasury\Http\Requests\F24DelegationRequest;
use Modules\Treasury\Http\Resources\F24DelegationResource;
use Modules\Treasury\Models\F24Delegation;
use Modules\Treasury\Models\F24TaxCode;
use Modules\Treasury\Services\F24\F24DelegationService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class F24DelegationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly F24DelegationService $delegations) {}

    #[OA\Get(
        path: '/api/v1/treasury/f24/tax-codes',
        operationId: 'listTreasuryF24TaxCodes',
        description: 'Reference tributo / causale codes of the Modello F24, optionally of one section.',
        summary: 'List F24 tax codes',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury F24'],
        parameters: [new OA\Parameter(name: 'section', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Codes', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function taxCodes(Request $request): JsonResponse
    {
        $codes = F24TaxCode::query()
            ->where('is_active', true)
            ->when($request->query('section'), fn ($query, string $section) => $query->where('section', $section))
            ->orderBy('section')->orderBy('code')
            ->get(['section', 'code', 'translation_key', 'requires_installment', 'period_format']);

        return $this->successResponse($codes);
    }

    #[OA\Get(
        path: '/api/v1/treasury/f24/delegations',
        operationId: 'listTreasuryF24Delegations',
        description: 'Modello F24 delegations of the company, newest payment date first.',
        summary: 'List F24 delegations',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury F24'],
        parameters: [new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Delegations', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        return $this->successResponse(F24DelegationResource::collection($this->delegations->list(is_string($status) ? $status : null)));
    }

    #[OA\Post(
        path: '/api/v1/treasury/f24/delegations',
        operationId: 'createTreasuryF24Delegation',
        description: 'Stores a draft Modello F24. Totals are computed with exact decimals.',
        summary: 'Create an F24 delegation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        tags: ['Treasury F24'],
        responses: [new OA\Response(response: 201, description: 'Delegation', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function store(F24DelegationRequest $request): JsonResponse
    {
        $delegation = $this->delegations->create($request->validated(), (string) $request->user()->id);

        return $this->successResponse(new F24DelegationResource($delegation), __('treasury_flows.messages.f24_created'), Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/treasury/f24/delegations/{id}',
        operationId: 'showTreasuryF24Delegation',
        description: 'A delegation with its lines.',
        summary: 'Show an F24 delegation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury F24'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Delegation', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new F24DelegationResource(F24Delegation::query()->with('lines')->findOrFail($id)));
    }

    #[OA\Put(
        path: '/api/v1/treasury/f24/delegations/{id}',
        operationId: 'updateTreasuryF24Delegation',
        description: 'Replaces taxpayer data and lines of a draft or validated delegation.',
        summary: 'Update an F24 delegation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        tags: ['Treasury F24'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Delegation', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function update(F24DelegationRequest $request, string $id): JsonResponse
    {
        $delegation = $this->delegations->update(F24Delegation::query()->findOrFail($id), $request->validated());

        return $this->successResponse(new F24DelegationResource($delegation));
    }

    #[OA\Delete(
        path: '/api/v1/treasury/f24/delegations/{id}',
        operationId: 'deleteTreasuryF24Delegation',
        description: 'Deletes a draft or validated delegation. A generated or paid one is kept.',
        summary: 'Delete an F24 delegation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury F24'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function destroy(string $id): JsonResponse
    {
        $this->delegations->delete(F24Delegation::query()->findOrFail($id));

        return $this->successResponse(null, __('treasury_flows.messages.f24_deleted'));
    }

    #[OA\Post(
        path: '/api/v1/treasury/f24/delegations/{id}/validate',
        operationId: 'validateTreasuryF24Delegation',
        description: 'Checks taxpayer data, section data and the balance of debits against compensated credits; answers with the list of problems (empty when valid).',
        summary: 'Validate an F24 delegation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury F24'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Validation result', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function validateDelegation(string $id): JsonResponse
    {
        $result = $this->delegations->validate(F24Delegation::query()->findOrFail($id));

        return $this->successResponse([
            'valid' => $result['issues'] === [],
            'issues' => array_map(static fn (array $issue): array => $issue + ['message' => __('treasury_flows.f24.issue.'.$issue['code'], $issue['params'])], $result['issues']),
            'delegation' => new F24DelegationResource($result['delegation']),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/treasury/f24/delegations/{id}/generate',
        operationId: 'generateTreasuryF24File',
        description: 'Generates and stores the telematic file of a delegation that passes validation.',
        summary: 'Generate the F24 telematic file',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury F24'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Delegation with file hash', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function generate(string $id): JsonResponse
    {
        $delegation = $this->delegations->generate(F24Delegation::query()->findOrFail($id));

        return $this->successResponse(new F24DelegationResource($delegation), __('treasury_flows.messages.f24_generated'));
    }

    #[OA\Get(
        path: '/api/v1/treasury/f24/delegations/{id}/download',
        operationId: 'downloadTreasuryF24File',
        description: 'The generated telematic file.',
        summary: 'Download the F24 file',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury F24'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'File')]
    )]
    public function download(string $id): \Illuminate\Http\Response
    {
        $delegation = F24Delegation::query()->findOrFail($id)->makeVisible('file_content');

        if ($delegation->file_content === null) {
            throw ValidationException::withMessages(['status' => __('treasury_flows.f24.not_generated')]);
        }

        return response((string) $delegation->file_content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$delegation->file_name.'"',
            'X-Content-SHA256' => (string) $delegation->file_hash,
        ]);
    }

    #[OA\Post(
        path: '/api/v1/treasury/f24/delegations/{id}/mark-paid',
        operationId: 'markTreasuryF24Paid',
        description: 'Marks a generated delegation as paid.',
        summary: 'Mark an F24 delegation as paid',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury F24'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Delegation', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function markPaid(string $id): JsonResponse
    {
        return $this->successResponse(new F24DelegationResource($this->delegations->markPaid(F24Delegation::query()->findOrFail($id))));
    }
}
