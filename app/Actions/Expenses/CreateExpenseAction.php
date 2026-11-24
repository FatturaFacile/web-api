<?php

declare(strict_types=1);

namespace App\Actions\Expenses;

use App\Events\Expenses\ExpenseCreated;
use App\Models\Expense;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CreateExpenseAction
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly TenantContext $tenantContext,
        private readonly AttachReceiptAction $attachReceipt,
    ) {}

    /**
     * Create an expense record in less than 30 seconds, optionally attaching receipts.
     *
     * @param  array<string, mixed>  $data
     * @param  UploadedFile|list<UploadedFile>|null  $receipts
     */
    public function execute(array $data, UploadedFile|array|null $receipts = null): Expense
    {
        $tenantId = $this->tenantContext->getTenantId();
        $date = $data['date'] ?? now()->toDateString();
        $fiscalYear = (int) date('Y', strtotime((string) $date));
        $numberData = $this->numbering->next('expense', $fiscalYear);
        $userId = Auth::id() ?? $data['user_id'] ?? null;

        // Auto-calculate net, tax, gross
        $taxRate = isset($data['tax_rate']) ? (float) $data['tax_rate'] : 22.00;

        if (isset($data['amount_gross']) && ! isset($data['amount_net'])) {
            $gross = round((float) $data['amount_gross'], 2);
            $net = $taxRate > 0 ? round($gross / (1 + ($taxRate / 100)), 2) : $gross;
            $taxAmount = round($gross - $net, 2);
        } elseif (isset($data['amount_net']) && ! isset($data['amount_gross'])) {
            $net = round((float) $data['amount_net'], 2);
            $taxAmount = round($net * ($taxRate / 100), 2);
            $gross = round($net + $taxAmount, 2);
        } else {
            $net = round((float) ($data['amount_net'] ?? 0), 2);
            $gross = round((float) ($data['amount_gross'] ?? $net), 2);
            $taxAmount = round($gross - $net, 2);
        }

        return DB::transaction(function () use ($data, $tenantId, $numberData, $date, $net, $taxRate, $taxAmount, $gross, $userId, $receipts): Expense {
            /** @var Expense $expense */
            $expense = Expense::create([
                'tenant_id' => $tenantId,
                'expense_number' => $numberData['number'],
                'category_id' => $data['category_id'],
                'date' => $date,
                'amount_net' => $net,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'amount_gross' => $gross,
                'currency' => $data['currency'] ?? 'EUR',
                'merchant_name' => $data['merchant_name'] ?? null,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'status' => $data['status'] ?? 'submitted',
                'is_billable' => (bool) ($data['is_billable'] ?? false),
                'is_reimbursable' => (bool) ($data['is_reimbursable'] ?? false),
                'notes' => $data['notes'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
                'user_id' => $userId,
                'project_id' => $data['project_id'] ?? null,
                'ticket_id' => $data['ticket_id'] ?? null,
                'service_report_id' => $data['service_report_id'] ?? null,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            // Attach receipt(s) if supplied
            if ($receipts instanceof UploadedFile) {
                $this->attachReceipt->execute($expense, $receipts, true);
            } elseif (is_array($receipts)) {
                foreach ($receipts as $receiptFile) {
                    if ($receiptFile instanceof UploadedFile) {
                        $this->attachReceipt->execute($expense, $receiptFile, true);
                    }
                }
            }

            event(new ExpenseCreated($expense));

            return $expense->load(['category.translations', 'expenseAttachments', 'contact', 'user']);
        });
    }
}
