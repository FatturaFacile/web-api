<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\GeneralLedger\Contracts\FinancialStatementServiceInterface;
use Modules\GeneralLedger\Contracts\PeriodClosingServiceInterface;
use Modules\GeneralLedger\Models\FiscalYear;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Yearly statements: comparison with the previous year, reclassification, cash flow and ratios.
 */
final class FinancialAnalysisController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly FinancialStatementServiceInterface $statements,
        private readonly PeriodClosingServiceInterface $closing,
        private readonly TenantContext $tenantContext,
    ) {}

    #[OA\Get(
        path: '/api/v1/general-ledger/financial-statements/comparative',
        operationId: 'getComparativeStatements',
        description: 'Income statement and balance sheet of a fiscal year next to the previous year.',
        summary: 'Comparative statements',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Financial Statements'],
        parameters: [new OA\Parameter(name: 'fiscal_year_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Statements', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerComparativeStatements')])),
            new OA\Response(response: 404, description: 'Fiscal year not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function comparative(Request $request): JsonResponse
    {
        return $this->successResponse($this->statements->comparativeStatements($this->tenantId(), (string) $request->query('fiscal_year_id')));
    }

    #[OA\Get(
        path: '/api/v1/general-ledger/financial-statements/reclassified',
        operationId: 'getReclassifiedStatement',
        description: 'A statement in the layout of a scheme, next to the previous year. Accounts no row picks up are listed in `unmapped_accounts`.',
        summary: 'Reclassified statement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Financial Statements'],
        parameters: [
            new OA\Parameter(name: 'scheme_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'fiscal_year_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Statement', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerReclassifiedStatement')])),
            new OA\Response(response: 404, description: 'Scheme or fiscal year not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function reclassified(Request $request): JsonResponse
    {
        return $this->successResponse($this->statements->reclassifiedStatement($this->tenantId(), (string) $request->query('scheme_id'), (string) $request->query('fiscal_year_id')));
    }

    #[OA\Get(
        path: '/api/v1/general-ledger/financial-statements/cash-flow-statement',
        operationId: 'getCashFlowStatement',
        description: 'Cash flow statement of a fiscal year, indirect method, proved against the change in cash.',
        summary: 'Cash flow statement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Financial Statements'],
        parameters: [new OA\Parameter(name: 'fiscal_year_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Statement', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerCashFlowStatement')])),
            new OA\Response(response: 404, description: 'Fiscal year not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function cashFlow(Request $request): JsonResponse
    {
        return $this->successResponse($this->statements->cashFlowStatement($this->tenantId(), (string) $request->query('fiscal_year_id')));
    }

    #[OA\Get(
        path: '/api/v1/general-ledger/financial-statements/ratios',
        operationId: 'getFinancialRatios',
        description: 'Liquidity, profitability and solvency ratios at the end of a fiscal year. A ratio without a denominator is null.',
        summary: 'Financial ratios',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Financial Statements'],
        parameters: [new OA\Parameter(name: 'fiscal_year_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Ratios', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerFinancialRatios')])),
            new OA\Response(response: 404, description: 'Fiscal year not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function ratios(Request $request): JsonResponse
    {
        return $this->successResponse($this->statements->financialRatios($this->tenantId(), (string) $request->query('fiscal_year_id')));
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/fiscal-years/{id}/carry-forward',
        operationId: 'carryForwardOpeningBalances',
        description: 'Post the balances of a closed year as the opening entry of the next one. Closing a year already does it when the next year exists or is a calendar year; this call covers the other cases. Repeating it returns the same entry.',
        summary: 'Carry forward opening balances',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Fiscal Years'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Opening entry', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'journal_entry_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'entry_number', type: 'string', nullable: true)])])),
            new OA\Response(response: 404, description: 'Fiscal year not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The year is not closed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function carryForward(Request $request, string $id): JsonResponse
    {
        FiscalYear::query()->findOrFail($id);

        try {
            $entry = $this->closing->carryForwardOpeningBalances($id, (string) $request->user()->id);
        } catch (InvalidArgumentException $exception) {
            return $this->problemDetailsResponse('Cannot carry forward', $exception->getMessage(), Response::HTTP_CONFLICT);
        }

        return $this->successResponse(['journal_entry_id' => $entry?->id, 'entry_number' => $entry?->entry_number]);
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }
}
