<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Controllers;

use App\Enums\SegregatedDocument;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Governance\Http\Controllers\Concerns\InteractsWithGovernance;
use Modules\Governance\Http\Requests\SodDocumentRuleRequest;
use Modules\Governance\Http\Resources\SodDocumentRuleResource;
use Modules\Governance\Models\GovSodRule;
use Modules\Governance\Services\DocumentSodService;
use OpenApi\Attributes as OA;

/**
 * Per-document segregation of duties: for payments, purchase orders, journals and payroll runs the company decides
 * whether the person who prepared the document can also approve or release it (block, warn or nothing).
 */
final class SodDocumentRuleController extends Controller
{
    use InteractsWithGovernance;

    public function __construct(private readonly DocumentSodService $documents) {}

    #[OA\Get(path: '/api/v1/governance/sod/document-rules', operationId: 'listGovSodDocumentRules', description: 'One entry per kind of document (payment, purchase_order, journal, payroll_run) with the rule of the company. configured is false while the company has not set the rule: payments, purchase orders and journals keep their built-in four-eyes check, payroll runs have none.', summary: 'List the per-document segregation rules', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], responses: [
        new OA\Response(response: 200, description: 'Rules', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GovSodDocumentRule'))])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
    ])]
    public function index(): JsonResponse
    {
        $this->authorize('create', GovSodRule::class);

        return response()->json(['data' => SodDocumentRuleResource::collection(collect($this->documents->rulesOf($this->tenantId())))]);
    }

    #[OA\Put(path: '/api/v1/governance/sod/document-rules/{documentType}', operationId: 'updateGovSodDocumentRule', description: 'Sets the rule of one kind of document: an active block rule refuses the approval or release by anyone who prepared the document, a warn rule lets it through and writes it on the audit ledger.', summary: 'Configure a per-document segregation rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovSodDocumentRuleRequest')), tags: ['Governance'], parameters: [
        new OA\Parameter(name: 'documentType', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['payment', 'purchase_order', 'journal', 'payroll_run'])),
    ], responses: [
        new OA\Response(response: 200, description: 'Rule saved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovSodDocumentRule')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function update(SodDocumentRuleRequest $request, string $documentType): SodDocumentRuleResource
    {
        $this->authorize('create', GovSodRule::class);
        $document = SegregatedDocument::tryFrom($documentType);
        abort_if($document === null, 404);

        $rule = $this->documents->configure($this->tenantId(), $document, (string) $request->validated('severity'), (bool) $request->validated('is_active'));

        return new SodDocumentRuleResource(['document_type' => $rule->document_type, 'severity' => $rule->severity, 'is_active' => $rule->is_active, 'configured' => true]);
    }
}
