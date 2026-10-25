<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class AccountingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    #[OA\Get(
        path: '/api/v1/accounting/chart-of-accounts',
        operationId: 'getChartOfAccounts',
        description: 'Get chart of accounts ordered by account code.',
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
        $accounts = DB::table('chart_of_accounts')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->get();

        return $this->successResponse(data: $accounts, message: 'Chart of accounts retrieved');
    }

    #[OA\Get(
        path: '/api/v1/accounting/ledger-entries',
        operationId: 'getLedgerEntries',
        description: 'Get paginated general ledger entries.',
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
    public function ledgerEntries(): JsonResponse
    {
        $entries = DB::table('general_ledger_entries')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->whereNull('deleted_at')
            ->latest('date')
            ->paginate(25);

        return $this->successResponse(data: $entries, message: 'General ledger entries retrieved');
    }

    #[OA\Post(
        path: '/api/v1/accounting/ledger-entries',
        operationId: 'createLedgerEntry',
        description: 'Post a new double-entry general ledger journal entry (must balance debit and credit).',
        summary: 'Post ledger entry',
        security: [['bearerAuth' => []]],
        tags: ['Accounting'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['entry_number', 'date', 'description', 'lines'],
                properties: [
                    new OA\Property(property: 'entry_number', type: 'string', example: 'GL-2026-0001'),
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
            new OA\Response(response: 201, description: 'General ledger entry posted successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Unbalanced ledger entry or validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function storeLedgerEntry(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entry_number' => ['required', 'string'],
            'date' => ['required', 'date'],
            'description' => ['required', 'string'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'uuid'],
            'lines.*.debit' => ['required', 'numeric', 'min:0'],
            'lines.*.credit' => ['required', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string'],
        ]);

        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($validated['lines'] as $line) {
            $totalDebit += (float) $line['debit'];
            $totalCredit += (float) $line['credit'];
        }

        // Enforce double-entry accounting equilibrium: sum(debit) == sum(credit)
        if (abs($totalDebit - $totalCredit) > 0.01) {
            return $this->problemDetailsResponse(
                title: 'Unbalanced Ledger Entry',
                detail: sprintf('Total debit (%.2f) does not equal total credit (%.2f). Double entry equilibrium required.', $totalDebit, $totalCredit),
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $validated): void {
            DB::table('general_ledger_entries')->insert([
                'id' => $id,
                'tenant_id' => $this->tenantContext->getTenantId(),
                'entry_number' => $validated['entry_number'],
                'date' => $validated['date'],
                'description' => $validated['description'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $lineNum = 1;
            foreach ($validated['lines'] as $line) {
                DB::table('general_ledger_lines')->insert([
                    'id' => (string) Str::uuid(),
                    'general_ledger_entry_id' => $id,
                    'account_id' => $line['account_id'],
                    'line_number' => $lineNum++,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'description' => $line['description'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return $this->successResponse(
            data: ['id' => $id, 'balanced_amount' => $totalDebit],
            message: 'General ledger entry posted successfully',
            statusCode: Response::HTTP_CREATED
        );
    }

    #[OA\Get(
        path: '/api/v1/accounting/vat-settlements',
        operationId: 'getVatSettlements',
        description: 'Get VAT settlements and periodic liquidations.',
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
        $settlements = DB::table('vat_settlements')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->whereNull('deleted_at')
            ->latest('year')
            ->get();

        return $this->successResponse(data: $settlements, message: 'VAT settlements retrieved');
    }
}
