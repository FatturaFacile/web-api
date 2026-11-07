<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Modules\GeneralLedger\Exceptions\FiscalPeriodException;
use Modules\GeneralLedger\Models\EnasarcoParameter;
use Modules\GeneralLedger\Models\EnasarcoPrincipalStatement;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Services\EnasarcoPrincipalService;
use Modules\GeneralLedger\Services\EnasarcoSummaryService;
use Modules\GeneralLedger\Services\Tax\Decimal;
use OpenApi\Attributes as OA;

/**
 * Enasarco parameters per year and period summary. Tax filings share the access rules of the VAT settlement.
 */
final class EnasarcoController extends Controller
{
    public function __construct(
        private readonly EnasarcoSummaryService $summaries,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Lists the Enasarco parameters visible to the tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/enasarco/parameters',
        operationId: 'listGeneralLedgerEnasarcoParameters',
        description: 'Lists the Enasarco parameters of the tenant and the generic ones (`tenant_id` null, read-only). Nothing is seeded: each year is entered with its source.',
        summary: 'List Enasarco parameters',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerEnasarcoParameter'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function parameters(): JsonResponse
    {
        $this->authorize('viewRegisters', VatSettlement::class);

        $tenantId = $this->tenantContext->getTenantId();

        $parameters = EnasarcoParameter::withoutGlobalScopes()
            ->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->orderByDesc('year')
            ->orderBy('agent_type')
            ->get();

        return response()->json(['data' => $parameters]);
    }

    /**
     * Stores the parameters of a year and agent type for the tenant.
     */
    #[OA\Put(
        path: '/api/v1/general-ledger/enasarco/parameters',
        operationId: 'saveGeneralLedgerEnasarcoParameter',
        description: 'Creates or replaces the tenant parameters of a year and agent type. They take precedence over a generic row, which stays untouched.',
        summary: 'Save Enasarco parameters',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['year', 'agent_type', 'rate_percent', 'agent_share_percent', 'annual_ceiling', 'source'], properties: [
            new OA\Property(property: 'year', type: 'integer'),
            new OA\Property(property: 'agent_type', type: 'string', enum: ['single_mandate', 'multi_mandate']),
            new OA\Property(property: 'rate_percent', description: 'Total contribution rate.', type: 'number'),
            new OA\Property(property: 'agent_share_percent', description: 'Share of the rate charged to the agent.', type: 'number'),
            new OA\Property(property: 'annual_ceiling', description: 'Commissions subject to the contribution per year and principal.', type: 'number'),
            new OA\Property(property: 'source', description: 'Where the figures come from.', type: 'string', maxLength: 255),
        ])),
        tags: ['General Ledger Tax Settlements'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerEnasarcoParameter')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function saveParameter(Request $request): JsonResponse
    {
        $this->authorize('calculate', VatSettlement::class);

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'agent_type' => ['required', Rule::in(EnasarcoParameter::AGENT_TYPES)],
            'rate_percent' => ['required', 'numeric', 'gt:0', 'max:100'],
            'agent_share_percent' => ['required', 'numeric', 'gt:0', 'max:100'],
            'annual_ceiling' => ['required', 'numeric', 'gt:0'],
            'source' => ['required', 'string', 'max:255'],
        ]);

        $parameter = EnasarcoParameter::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $this->tenantContext->getTenantId(), 'year' => $validated['year'], 'agent_type' => $validated['agent_type']],
            $validated
        );

        return response()->json(['data' => $parameter]);
    }

    /**
     * Returns the Enasarco summary of a year or quarter.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/enasarco/summary',
        operationId: 'getGeneralLedgerEnasarcoSummary',
        description: 'Returns the Enasarco of a year or quarter by counterparty: what the principals kept from the tenant on issued invoices, and what the tenant kept from its agents on received invoices.',
        summary: 'Get Enasarco summary',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', required: true, description: 'Year', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'quarter', in: 'query', required: false, description: 'Quarter, the whole year when omitted', schema: new OA\Schema(type: 'integer', maximum: 4, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewRegisters', VatSettlement::class);

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'quarter' => ['nullable', 'integer', 'min:1', 'max:4'],
        ]);

        return response()->json(['data' => $this->summaries->summary(
            (string) $this->tenantContext->getTenantId(),
            (int) $validated['year'],
            isset($validated['quarter']) ? (int) $validated['quarter'] : null
        )]);
    }

    /**
     * Returns the quarterly statement of the tenant as a principal.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/enasarco/principal-statements',
        operationId: 'listGeneralLedgerEnasarcoPrincipalStatements',
        description: 'The quarterly Enasarco statement (distinta) of the tenant as a principal, one line per agent: commissions, the part below the annual ceiling, the agent share withheld on the received invoices (RT04) and the principal share.',
        summary: 'List Enasarco principal statement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', required: true, description: 'Year', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'quarter', in: 'query', required: true, description: 'Quarter', schema: new OA\Schema(type: 'integer', maximum: 4, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerEnasarcoPrincipalStatementResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function principalStatements(Request $request, EnasarcoPrincipalService $principal): JsonResponse
    {
        $this->authorize('viewRegisters', VatSettlement::class);

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'quarter' => ['required', 'integer', 'min:1', 'max:4'],
        ]);

        return $this->principalResponse($principal->statement((string) $this->tenantContext->getTenantId(), (int) $validated['year'], (int) $validated['quarter']));
    }

    /**
     * Computes the quarterly statement of the tenant as a principal and posts its share.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/enasarco/principal-statements',
        operationId: 'generateGeneralLedgerEnasarcoPrincipalStatement',
        description: 'Computes the quarterly statement from the received invoices with an Enasarco block and the parameters of the year (rate, agent share, annual ceiling per agent), and posts the principal share once per agent and quarter: debit of the principal contribution cost, credit of the contributions payable. Agents already in the statement keep their line. The agent type of each agent comes from `agent_types`, else from `agent_type`.',
        summary: 'Generate Enasarco principal statement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerEnasarcoPrincipalStatementRequest')),
        tags: ['General Ledger Tax Settlements'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerEnasarcoPrincipalStatementResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed, parameters of the year missing or period closed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function generatePrincipalStatement(Request $request, EnasarcoPrincipalService $principal): JsonResponse
    {
        $this->authorize('calculate', VatSettlement::class);

        $tenantId = (string) $this->tenantContext->getTenantId();
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'quarter' => ['required', 'integer', 'min:1', 'max:4'],
            'agent_type' => ['required', Rule::in(EnasarcoParameter::AGENT_TYPES)],
            'agent_types' => ['sometimes', 'array'],
            'agent_types.*' => ['required', Rule::in(EnasarcoParameter::AGENT_TYPES)],
        ]);

        try {
            $lines = $principal->generate($tenantId, (int) $validated['year'], (int) $validated['quarter'], $validated['agent_type'], $validated['agent_types'] ?? [], (string) $request->user()?->id);
        } catch (FiscalPeriodException $e) {
            return $e->render();
        }

        return $this->principalResponse($lines);
    }

    /**
     * @param  Collection<int, EnasarcoPrincipalStatement>  $lines
     */
    private function principalResponse(Collection $lines): JsonResponse
    {
        return response()->json(['data' => [
            'lines' => $lines->values(),
            'total_agent_withheld' => $lines->reduce(static fn (string $sum, EnasarcoPrincipalStatement $line): string => Decimal::round2(Decimal::add($sum, (string) $line->agent_withheld)), '0.00'),
            'total_principal_amount' => $lines->reduce(static fn (string $sum, EnasarcoPrincipalStatement $line): string => Decimal::round2(Decimal::add($sum, (string) $line->principal_amount)), '0.00'),
            'total_due' => $lines->reduce(static fn (string $sum, EnasarcoPrincipalStatement $line): string => Decimal::round2(Decimal::add($sum, (string) $line->total_due)), '0.00'),
        ]]);
    }
}
