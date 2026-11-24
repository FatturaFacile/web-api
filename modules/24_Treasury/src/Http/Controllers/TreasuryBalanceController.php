<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Services\BankBalanceService;
use Modules\Treasury\Services\LiquidityForecastService;
use OpenApi\Attributes as OA;

/**
 * Balances of the bank accounts at a date (book, statement, snapshot), the daily history of one account and the cash
 * position over bank accounts and cash registers.
 */
final class TreasuryBalanceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BankBalanceService $balances,
        private readonly LiquidityForecastService $liquidity,
    ) {}

    /**
     * The balances of every active bank account at a date.
     */
    #[OA\Get(
        path: '/api/v1/treasury/balances',
        operationId: 'getTreasuryBalancesAtDate',
        description: 'Balances of every active bank account at a date: book balance of its ledger account (posted lines up to the date), closing balance of the last bank statement on or before the date, end-of-day snapshot and current treasury balance. The difference between book and statement balance is what is still to reconcile.',
        summary: 'Bank balances at a date',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Bank Accounts'],
        parameters: [new OA\Parameter(name: 'date', description: 'Default: today', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [
            new OA\Response(response: 200, description: 'Balances', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryBankBalancesEnvelope')),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function balances(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = isset($data['date']) ? CarbonImmutable::createFromFormat('!Y-m-d', $data['date']) : CarbonImmutable::today();

        return $this->successResponse($this->balances->balancesAt((string) $tenantContext->getTenantId(), $date), 'Bank balances');
    }

    /**
     * The daily book balance of one bank account over a period.
     */
    #[OA\Get(
        path: '/api/v1/treasury/bank-accounts/{id}/balances',
        operationId: 'getTreasuryBankAccountBalanceHistory',
        description: 'Daily book balance of a bank account between two dates (at most one year): the opening balance and, for every day with ledger movements, debits, credits and running balance, plus the balances at the last day.',
        summary: 'Bank account balance history',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Bank Accounts'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/TreasuryBankAccountId'),
            new OA\Parameter(ref: '#/components/parameters/TreasuryFromDate'),
            new OA\Parameter(ref: '#/components/parameters/TreasuryToDate'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Balance history', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryBankBalanceHistoryEnvelope')),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function history(Request $request, TenantContext $tenantContext, string $id): JsonResponse
    {
        $account = BankAccount::query()->findOrFail($id);
        $data = $request->validate([
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ]);
        $to = isset($data['to_date']) ? CarbonImmutable::createFromFormat('!Y-m-d', $data['to_date']) : CarbonImmutable::today();
        $from = isset($data['from_date']) ? CarbonImmutable::createFromFormat('!Y-m-d', $data['from_date']) : $to->startOfMonth();

        if ($to->greaterThanOrEqualTo($from->addYear())) {
            return response()->json(['message' => 'The period is at most one year.', 'errors' => ['to_date' => ['The period is at most one year.']]], 422);
        }

        $tenantId = (string) $tenantContext->getTenantId();

        return $this->successResponse([
            'bank_account_id' => $account->id,
            'currency' => $account->currency,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            ...$this->balances->history($tenantId, $account, $from, $to),
            'at_end' => $this->balances->balanceOf($tenantId, $account, $to),
        ], 'Bank account balance history');
    }

    /**
     * The cash position over bank accounts and cash registers.
     */
    #[OA\Get(
        path: '/api/v1/treasury/positions/cash',
        operationId: 'getTreasuryCashPosition',
        description: 'Current cash position: balance of every active bank account and cash register, and the totals per currency (amounts are decimal strings, currencies are never summed together).',
        summary: 'Cash position',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Positions'],
        responses: [
            new OA\Response(response: 200, description: 'Cash position', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryCashPositionEnvelope')),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
        ]
    )]
    public function position(): JsonResponse
    {
        return $this->successResponse($this->liquidity->position(), 'Cash position');
    }
}
