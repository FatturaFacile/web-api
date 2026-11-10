<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\RibaReceipt;
use OpenApi\Attributes as OA;

/**
 * The Ri.Ba. portfolio: the collection batches and the receipts by status (pending, presented, paid, unpaid), with the
 * open receipts by due date.
 */
final class RibaPortfolioController extends Controller
{
    use ApiResponse;

    /**
     * The Ri.Ba. batches, newest first.
     */
    #[OA\Get(
        path: '/api/v1/treasury/riba/batches',
        operationId: 'listRibaBatches',
        description: 'Lists the Ri.Ba. collection batches of the active tenant, newest first, optionally by status or bank account.',
        summary: 'List Ri.Ba. batches',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Ri.Ba.'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'bank_account_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Batches', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryRibaBatchListEnvelope')),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function batches(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', 'string', 'max:30'], 'bank_account_id' => ['nullable', 'uuid']]);

        $batches = PaymentBatch::query()
            ->where('payment_method', 'riba')
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['bank_account_id'] ?? null, fn ($query, string $id) => $query->where('bank_account_id', $id))
            ->latest()
            ->limit(200)
            ->get()
            ->map(static fn (PaymentBatch $batch): array => [
                'id' => $batch->id,
                'batch_reference' => $batch->batch_reference,
                'bank_account_id' => $batch->bank_account_id,
                'status' => $batch->status,
                'execution_date' => CarbonImmutable::parse($batch->execution_date)->toDateString(),
                'total_amount' => Decimal::round2((string) $batch->total_amount),
                'currency' => $batch->currency,
                'item_count' => (int) $batch->item_count,
            ]);

        return $this->successResponse($batches->values()->all(), 'Ri.Ba. batches');
    }

    /**
     * The portfolio of receipts: totals by status and the open receipts.
     */
    #[OA\Get(
        path: '/api/v1/treasury/riba/portfolio',
        operationId: 'getRibaPortfolio',
        description: 'Ri.Ba. portfolio: count and amount of the receipts by status, and the open receipts (pending or presented) by due date, flagged overdue when a presented receipt is past its due date without an outcome. Filters: bank account and due-date range.',
        summary: 'Ri.Ba. portfolio',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Ri.Ba.'],
        parameters: [
            new OA\Parameter(name: 'bank_account_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'due_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'due_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Portfolio', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryRibaPortfolioEnvelope')),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function portfolio(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'bank_account_id' => ['nullable', 'uuid'],
            'due_from' => ['nullable', 'date_format:Y-m-d'],
            'due_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:due_from'],
        ]);
        $today = CarbonImmutable::today();

        $receipts = RibaReceipt::query()
            ->when($filters['bank_account_id'] ?? null, fn ($query, string $id) => $query->whereIn('payment_batch_id', PaymentBatch::query()->where('bank_account_id', $id)->select('id')))
            ->when($filters['due_from'] ?? null, fn ($query, string $date) => $query->whereDate('due_date', '>=', $date))
            ->when($filters['due_to'] ?? null, fn ($query, string $date) => $query->whereDate('due_date', '<=', $date))
            ->orderBy('due_date')
            ->get();

        $byStatus = [];

        foreach ($receipts as $receipt) {
            $byStatus[$receipt->status]['count'] = ($byStatus[$receipt->status]['count'] ?? 0) + 1;
            $byStatus[$receipt->status]['amount'] = Decimal::round2(Decimal::add($byStatus[$receipt->status]['amount'] ?? '0', (string) $receipt->amount));
        }

        return $this->successResponse([
            'by_status' => $byStatus,
            'open_receipts' => $receipts->whereIn('status', ['pending', 'presented'])->map(static fn (RibaReceipt $receipt): array => [
                'id' => $receipt->id,
                'payment_batch_id' => $receipt->payment_batch_id,
                'invoice_id' => $receipt->invoice_id,
                'receipt_number' => (int) $receipt->receipt_number,
                'debtor_name' => $receipt->debtor_name,
                'due_date' => CarbonImmutable::parse($receipt->due_date)->toDateString(),
                'amount' => Decimal::round2((string) $receipt->amount),
                'status' => $receipt->status,
                'overdue' => $receipt->status === 'presented' && CarbonImmutable::parse($receipt->due_date)->lessThan($today),
            ])->values()->all(),
        ], 'Ri.Ba. portfolio');
    }
}
