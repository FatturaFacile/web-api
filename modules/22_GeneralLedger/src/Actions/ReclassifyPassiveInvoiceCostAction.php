<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Actions;

use App\Models\PassiveInvoice;
use App\Models\PassiveInvoiceLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;

/**
 * Manual override of the cost account of a received document. Before the posting it only changes the document. After
 * the posting the original entry stays untouched and a reclassification entry moves the amount between the accounts.
 */
final readonly class ReclassifyPassiveInvoiceCostAction
{
    private const string DefaultCostAccountCode = '6000';

    public function __construct(
        private DoubleEntryPostingServiceInterface $postingService,
        private FiscalPeriodResolver $periods,
    ) {}

    /**
     * @param  list<string>  $lineIds  empty means every line of the document
     */
    public function execute(PassiveInvoice $invoice, string $accountId, array $lineIds = []): PassiveInvoice
    {
        return DB::transaction(function () use ($invoice, $accountId, $lineIds): PassiveInvoice {
            $invoice = PassiveInvoice::lockForUpdate()->findOrFail($invoice->id);
            $lines = $invoice->lines()->when($lineIds !== [], static fn ($query) => $query->whereIn('id', $lineIds))->get();
            $moved = $this->amountsToMove($invoice, $lines->all(), $accountId);

            $lines->each->update(['cost_account_id' => $accountId, 'cost_account_source' => 'manual']);

            if ($lineIds === []) {
                $invoice->update(['cost_account_id' => $accountId, 'cost_account_source' => 'manual']);
            }

            if ($invoice->gl_journal_entry_id !== null && $moved !== []) {
                $this->postReclassification($invoice, $accountId, $moved);
            }

            return $invoice->refresh();
        });
    }

    /**
     * @param  list<PassiveInvoiceLine>  $lines
     * @return array<string, string> amount leaving each account
     */
    private function amountsToMove(PassiveInvoice $invoice, array $lines, string $target): array
    {
        $default = $this->defaultAccountId($invoice);
        $fallback = (string) ($invoice->cost_account_id ?? $default);
        $moved = [];

        if ($lines === []) {
            $net = bcadd((string) abs((float) $invoice->total_net), '0', 2);

            return $fallback === $target || bccomp($net, '0', 2) === 0 ? [] : [$fallback => $net];
        }

        foreach ($lines as $line) {
            $from = (string) ($line->cost_account_id ?? $fallback);

            if ($from !== $target) {
                $moved[$from] = bcadd($moved[$from] ?? '0.00', (string) abs((float) $line->line_total), 2);
            }
        }

        return $moved;
    }

    private function defaultAccountId(PassiveInvoice $invoice): string
    {
        return (string) ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $invoice->tenant_id)
            ->where('account_code', self::DefaultCostAccountCode)
            ->value('id');
    }

    /**
     * @param  array<string, string>  $moved
     */
    private function postReclassification(PassiveInvoice $invoice, string $target, array $moved): void
    {
        $tenantId = (string) $invoice->tenant_id;
        $today = now()->toDateString();
        $period = $this->periods->forDate($tenantId, $today);
        $isCreditNote = $invoice->document_type === 'TD04';
        $currency = TenantAccountingCurrency::of($tenantId);
        $description = "Cost reclassification: invoice {$invoice->invoice_number}";
        $lines = [];

        foreach ($moved as $from => $amount) {
            $value = (float) $amount;
            $lines[] = ['account_id' => $target, 'debit_amount' => $isCreditNote ? 0.0 : $value, 'credit_amount' => $isCreditNote ? $value : 0.0, 'currency' => $currency, 'exchange_rate' => (float) ($invoice->exchange_rate ?? 1.0), 'description' => $description];
            $lines[] = ['account_id' => $from, 'debit_amount' => $isCreditNote ? $value : 0.0, 'credit_amount' => $isCreditNote ? 0.0 : $value, 'currency' => $currency, 'exchange_rate' => (float) ($invoice->exchange_rate ?? 1.0), 'description' => $description];
        }

        $this->postingService->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'fiscal_period_id' => $period->id,
            'posting_date' => $today,
            'document_date' => $today,
            'entry_number' => 'COST-RCL-'.strtoupper(Str::random(6)),
            'source_document_type' => 'vendor_invoice_reclassification',
            'source_document_id' => (string) Str::uuid7(),
            'document_number' => (string) $invoice->invoice_number,
            'journal_type' => 'standard',
            'description' => $description,
            'lines' => $lines,
        ]);
    }
}
