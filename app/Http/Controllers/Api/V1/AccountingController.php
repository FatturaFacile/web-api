<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Concerns\TenantScopedRules;
use App\Http\Responses\ApiResponse;
use App\Models\ChartOfAccount;
use App\Models\VatSettlement;
use App\Services\Accounting\LegacyLedgerBridge;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Modules\GeneralLedger\Exceptions\FiscalPeriodException;
use Modules\GeneralLedger\Exceptions\LedgerPostingException;
use Modules\GeneralLedger\Http\Resources\JournalEntryResource;
use Modules\GeneralLedger\Services\Tax\Decimal;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class AccountingController extends Controller
{
    use ApiResponse, TenantScopedRules;

    /**
     * @deprecated Use GET /api/v1/general-ledger/accounts.
     */
    #[OA\Get(
        path: '/api/v1/accounting/chart-of-accounts',
        operationId: 'getChartOfAccounts',
        deprecated: true,
        description: 'Deprecated: use GET /api/v1/general-ledger/accounts (responses carry X-Deprecated and a Link successor-version header). Get chart of accounts ordered by account code.',
        summary: 'Get chart of accounts',
        security: [['bearerAuth' => []]],
        tags: ['Accounting'],
        responses: [
            new OA\Response(response: 200, description: 'Chart of accounts retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function chartOfAccounts(): JsonResponse
    {
        $accounts = ChartOfAccount::orderBy('code')->get();

        return $this->successResponse(data: $accounts, message: 'Chart of accounts retrieved');
    }

    /**
     * Legacy listing of the ledger. It reads the journal entries of the General Ledger module (the one ledger of the
     * application), so it shows the same entries and figures as `/api/v1/general-ledger/journals`, in the legacy row
     * shape plus the journal fields (`journal_number`, `fiscal_period_id`, `status`, `total_amount`).
     *
     * @deprecated Use GET /api/v1/general-ledger/journals.
     */
    #[OA\Get(
        path: '/api/v1/accounting/ledger-entries',
        operationId: 'getLedgerEntries',
        deprecated: true,
        description: 'Get paginated general ledger entries. Reads the journal entries of the General Ledger module (posted and reversed; drafts are excluded) in the legacy row shape, with `journal_entry_id`, `journal_number`, `fiscal_period_id`, `status` and `total_amount` added. Deprecated: use GET /api/v1/general-ledger/journals (responses carry X-Deprecated and a Link successor-version header).',
        summary: 'List ledger entries',
        security: [['bearerAuth' => []]],
        tags: ['Accounting'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'General ledger entries retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function ledgerEntries(Request $request, LegacyLedgerBridge $ledger, TenantContext $tenantContext): JsonResponse
    {
        $entries = $ledger->paginate($this->ledgerTenantId($request, $tenantContext));

        return $this->successResponse(data: $entries, message: 'General ledger entries retrieved');
    }

    /**
     * Legacy posting of a ledger entry, kept with the same request contract but delegated to the posting service of
     * the General Ledger module: the entry lands in `journal_entries`, in the fiscal period of its date (refused when
     * that period is missing, closed or locked), with an exact balance check and the libro giornale number.
     *
     * @deprecated Use POST /api/v1/general-ledger/journals.
     */
    #[OA\Post(
        path: '/api/v1/accounting/ledger-entries',
        operationId: 'createLedgerEntry',
        description: 'Deprecated: use POST /api/v1/general-ledger/journals. Posts a balanced double-entry journal entry through the General Ledger module: it is booked in the fiscal period containing `date` (422 when the period is missing, closed or locked), debit and credit must balance exactly, and the entry gets its libro giornale number. The response carries the posted journal entry.',
        summary: 'Post ledger entry (deprecated)',
        security: [['bearerAuth' => []]],
        deprecated: true,
        tags: ['Accounting'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['entry_number', 'date', 'description', 'lines'],
                properties: [
                    new OA\Property(property: 'entry_number', type: 'string', maxLength: 50, example: 'GL-2026-0001'),
                    new OA\Property(property: 'date', type: 'string', format: 'date', example: '2026-10-25'),
                    new OA\Property(property: 'description', type: 'string', example: 'Monthly payroll posting'),
                    new OA\Property(
                        property: 'lines',
                        type: 'array',
                        items: new OA\Items(
                            required: ['account_id', 'debit', 'credit'],
                            properties: [
                                new OA\Property(property: 'account_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'debit', type: 'number', format: 'float', example: 500.00),
                                new OA\Property(property: 'credit', type: 'number', format: 'float', example: 0.00),
                                new OA\Property(property: 'description', type: 'string', nullable: true),
                            ]
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Journal entry posted: `data.id` and `data.journal_entry.id` are the id of the journal entry', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Unbalanced entry, fiscal period missing/closed/locked, or validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function storeLedgerEntry(Request $request, LegacyLedgerBridge $ledger, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $this->ledgerTenantId($request, $tenantContext);

        $validated = $request->validate([
            'entry_number' => ['required', 'string', 'max:50', Rule::unique('journal_entries', 'entry_number')->where('tenant_id', $tenantId)],
            'date' => ['required', 'date'],
            'description' => ['required', 'string'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'uuid', $this->tenantExists('chart_of_accounts')],
            'lines.*.debit' => ['required', 'numeric', 'min:0'],
            'lines.*.credit' => ['required', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string'],
        ]);

        try {
            $journalLines = $ledger->journalLines($validated['lines']);
        } catch (InvalidArgumentException $exception) {
            return $this->problemDetailsResponse(title: 'Invalid Ledger Entry', detail: $exception->getMessage(), status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $totals = $ledger->totals($journalLines);

        if (! $ledger->isBalanced($journalLines)) {
            return $this->problemDetailsResponse(
                title: 'Unbalanced Ledger Entry',
                detail: sprintf('Total debit (%s) does not equal total credit (%s). Double entry equilibrium required.', Decimal::round2($totals['debit']), Decimal::round2($totals['credit'])),
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $actorId = $request->user()?->id;
            $entry = $ledger->post($tenantId, $validated, $actorId !== null ? (string) $actorId : null);
        } catch (FiscalPeriodException|LedgerPostingException $exception) {
            return $exception->render();
        } catch (InvalidArgumentException $exception) {
            return $this->problemDetailsResponse(title: 'Ledger Entry Refused', detail: $exception->getMessage(), status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->successResponse(
            data: [
                'id' => (string) $entry->id,
                'balanced_amount' => (float) $totals['debit'],
                'journal_entry' => new JournalEntryResource($entry),
            ],
            message: 'General ledger entry posted successfully',
            statusCode: Response::HTTP_CREATED
        );
    }

    /**
     * The tenant the legacy ledger endpoints act for: the active tenant of the request, as the module endpoints do.
     */
    private function ledgerTenantId(Request $request, TenantContext $tenantContext): string
    {
        $tenantId = $tenantContext->getTenantId() ?? $request->user()?->tenant_id;

        if (! $tenantId) {
            abort(Response::HTTP_FORBIDDEN, 'Missing or unresolvable tenant context');
        }

        return (string) $tenantId;
    }

    /**
     * @deprecated Use GET /api/v1/general-ledger/tax-settlements.
     */
    #[OA\Get(
        path: '/api/v1/accounting/vat-settlements',
        operationId: 'getVatSettlements',
        deprecated: true,
        description: 'Deprecated: use GET /api/v1/general-ledger/tax-settlements (responses carry X-Deprecated and a Link successor-version header). Get VAT settlements and periodic liquidations.',
        summary: 'Get VAT settlements',
        security: [['bearerAuth' => []]],
        tags: ['Accounting'],
        responses: [
            new OA\Response(response: 200, description: 'VAT settlements retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function vatSettlements(): JsonResponse
    {
        $settlements = VatSettlement::latest('year')->get();

        return $this->successResponse(data: $settlements, message: 'VAT settlements retrieved');
    }
}
