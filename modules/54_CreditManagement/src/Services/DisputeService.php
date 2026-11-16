<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CreditManagement\Contracts\ReceivablesLookupInterface;
use Modules\CreditManagement\Models\CreditCaseInvoice;
use Modules\CreditManagement\Models\CreditCollectionCase;
use Modules\CreditManagement\Models\CreditDispute;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Disputes on invoices. The disputed amount cannot exceed what is still due on the invoice. A dispute raised on an
 * invoice that is under collection joins that case and makes it disputed until every dispute of the case is decided.
 * An upheld dispute carries the amount credited to the customer (never above the disputed amount); the credit note
 * itself is issued in the invoicing module.
 */
final readonly class DisputeService
{
    public function __construct(private ReceivablesLookupInterface $receivables, private CollectionCaseService $cases) {}

    /**
     * @param  array{customer_id: string, invoice_id?: string|null, case_id?: string|null, disputed_amount: string|int|float, reason_code: string, description?: string|null}  $data
     */
    public function create(string $tenantId, array $data): CreditDispute
    {
        $customerId = (string) $data['customer_id'];

        if ($this->receivables->customerName($tenantId, $customerId) === null) {
            throw ValidationException::withMessages(['customer_id' => [__('credit_errors.customer.missing')]]);
        }

        $amount = Decimal::round2((string) $data['disputed_amount']);
        $invoiceId = $data['invoice_id'] ?? null;

        if ($invoiceId !== null) {
            $invoice = collect($this->receivables->openInvoices($tenantId, $customerId))->firstWhere('invoice_id', $invoiceId);

            if ($invoice === null) {
                throw ValidationException::withMessages(['invoice_id' => [__('credit_errors.dispute.invoice_not_open')]]);
            }

            if (Decimal::compare($amount, $invoice['outstanding']) > 0) {
                throw ValidationException::withMessages(['disputed_amount' => [__('credit_errors.dispute.amount_exceeds', ['outstanding' => $invoice['outstanding']])]]);
            }
        }

        return DB::transaction(function () use ($tenantId, $customerId, $data, $amount, $invoiceId): CreditDispute {
            $caseId = $data['case_id'] ?? null;

            if ($caseId === null && $invoiceId !== null) {
                $caseId = CreditCaseInvoice::query()
                    ->where('tenant_id', $tenantId)
                    ->where('invoice_id', $invoiceId)
                    ->whereIn('case_id', CreditCollectionCase::query()->where('tenant_id', $tenantId)->whereNotIn('status', CreditCollectionCase::ClosedStatuses)->select('id'))
                    ->value('case_id');
            }

            $case = null;

            if ($caseId !== null) {
                $case = CreditCollectionCase::query()->where('tenant_id', $tenantId)->where('customer_id', $customerId)->find($caseId);

                if ($case === null || $case->isClosed()) {
                    throw ValidationException::withMessages(['case_id' => [__('credit_errors.dispute.case_mismatch')]]);
                }
            }

            $dispute = CreditDispute::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customerId,
                'case_id' => $case?->id,
                'invoice_id' => $invoiceId,
                'disputed_amount' => $amount,
                'reason_code' => $data['reason_code'],
                'status' => CreditDispute::Open,
                'opened_on' => Carbon::today()->toDateString(),
                'description' => $data['description'] ?? null,
            ]);

            if ($case !== null) {
                $this->cases->settle($case);
            }

            return $dispute->refresh();
        });
    }

    public function startReview(CreditDispute $dispute): CreditDispute
    {
        if ($dispute->status !== CreditDispute::Open) {
            throw ValidationException::withMessages(['status' => [__('credit_errors.dispute.not_open')]]);
        }

        $dispute->update(['status' => CreditDispute::UnderReview]);

        return $dispute->refresh();
    }

    /**
     * Decides the dispute as upheld (the customer is right) or rejected.
     *
     * @param  array{outcome: string, credited_amount?: string|int|float|null, resolution_note?: string|null}  $data
     */
    public function resolve(CreditDispute $dispute, array $data): CreditDispute
    {
        return DB::transaction(function () use ($dispute, $data): CreditDispute {
            $dispute = CreditDispute::query()->whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            if (! in_array($dispute->status, CreditDispute::OpenStatuses, true)) {
                throw ValidationException::withMessages(['status' => [__('credit_errors.dispute.not_open')]]);
            }

            $credited = null;

            if ($data['outcome'] === CreditDispute::Upheld) {
                $credited = isset($data['credited_amount']) ? Decimal::round2((string) $data['credited_amount']) : null;

                if ($credited === null || ! Decimal::isPositive($credited)) {
                    throw ValidationException::withMessages(['credited_amount' => [__('credit_errors.dispute.credit_required')]]);
                }

                if (Decimal::compare($credited, (string) $dispute->disputed_amount) > 0) {
                    throw ValidationException::withMessages(['credited_amount' => [__('credit_errors.dispute.credit_exceeds', ['disputed' => $dispute->disputed_amount])]]);
                }
            }

            $dispute->update(['status' => $data['outcome'], 'credited_amount' => $credited, 'resolved_on' => Carbon::today()->toDateString(), 'resolution_note' => $data['resolution_note'] ?? null]);

            if ($dispute->case_id !== null) {
                $case = CreditCollectionCase::query()->find($dispute->case_id);

                if ($case !== null) {
                    $this->cases->refresh($case);
                }
            }

            return $dispute->refresh();
        });
    }
}
