<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Http\Requests\StoreFxRevaluationRequest;
use Modules\GeneralLedger\Http\Resources\LedgerDateFormat;
use Modules\GeneralLedger\Models\FxRevaluation;
use Modules\GeneralLedger\Services\OpenItemFxRevaluationService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Period-end revaluation of open foreign-currency receivables and payables (bank accounts are revalued by Treasury).
 */
final class OpenItemFxRevaluationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OpenItemFxRevaluationService $revaluations,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * The revaluations already run.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/fx-revaluations',
        operationId: 'listGeneralLedgerFxRevaluations',
        description: 'Revaluations of open receivables and payables already run, newest first, optionally of one date.',
        summary: 'List open item FX revaluations',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger FX Revaluation'],
        parameters: [new OA\Parameter(name: 'revaluation_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [
            new OA\Response(response: 200, description: 'Revaluations', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerFxRevaluationResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'No ledger read access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $rows = FxRevaluation::query()
            ->when($request->query('revaluation_date'), static fn ($query, $date) => $query->whereDate('revaluation_date', $date))
            ->orderByDesc('revaluation_date')
            ->orderBy('side')
            ->orderBy('currency')
            ->gridPaginate(min(1000, max(1, (int) $request->query('per_page', 1000))));

        return $this->successResponse(
            data: $rows->getCollection()->map(fn (FxRevaluation $row): array => $this->present($row))->values(),
            meta: ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()],
        );
    }

    /**
     * Revalues the open items at a date.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/fx-revaluations',
        operationId: 'runGeneralLedgerFxRevaluation',
        description: 'Revalues the open receivables and payables in a foreign currency at `revaluation_date` (optionally one currency only), with the convention of the Treasury bank revaluation: closing rate from the exchange-rate service (latest on or before the date), book value at the historical rate of each item, difference posted per currency and control account against the unrealised exchange gain or loss account (default posting accounts, falling back to banking.ledger_defaults.fx_unrealised_*_account_code), and reversed automatically on the next day. Once per date, side, currency and account: a repeated call returns what was already posted. Refused with 422 when there is nothing to revalue, or a rate, a gain/loss account or the fiscal period is missing.',
        summary: 'Run open item FX revaluation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger FX Revaluation'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['revaluation_date'], properties: [
            new OA\Property(property: 'revaluation_date', type: 'string', format: 'date', example: '2027-12-31'),
            new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, nullable: true, example: 'USD'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Revaluations posted', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerFxRevaluationResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Nothing to revalue, missing rate, gain/loss account or fiscal period', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreFxRevaluationRequest $request): JsonResponse
    {
        $rows = $this->revaluations->revalue(
            (string) $this->tenantContext->getTenantId(),
            CarbonImmutable::parse((string) $request->validated('revaluation_date')),
            $request->validated('currency'),
            (string) $request->user()->id
        );

        return $this->successResponse(array_map(fn (FxRevaluation $row): array => $this->present($row), $rows), 'Open items revalued', Response::HTTP_CREATED);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(FxRevaluation $row): array
    {
        return [
            'id' => (string) $row->id,
            'revaluation_date' => LedgerDateFormat::day($row->revaluation_date),
            'side' => $row->side,
            'currency' => $row->currency,
            'account_id' => (string) $row->account_id,
            'closing_rate' => (string) $row->closing_rate,
            'open_amount' => (string) $row->open_amount,
            'book_value' => (string) $row->book_value,
            'revalued_value' => (string) $row->revalued_value,
            'difference' => (string) $row->difference,
            'open_items' => $row->open_items,
            'journal_entry_id' => $row->journal_entry_id,
            'reversal_date' => LedgerDateFormat::day($row->reversal_date),
            'imparity_deferred' => (bool) $row->imparity_deferred,
            'treatment_applied' => $row->treatment_applied,
        ];
    }
}
