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
use Symfony\Component\HttpFoundation\Response;

class AccountingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    public function chartOfAccounts(): JsonResponse
    {
        $accounts = DB::table('chart_of_accounts')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->get();

        return $this->successResponse(data: $accounts, message: 'Chart of accounts retrieved');
    }

    public function ledgerEntries(): JsonResponse
    {
        $entries = DB::table('general_ledger_entries')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->whereNull('deleted_at')
            ->latest('date')
            ->paginate(25);

        return $this->successResponse(data: $entries, message: 'General ledger entries retrieved');
    }

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
