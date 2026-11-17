<?php

declare(strict_types=1);

namespace Modules\Customs\Services;

use App\Models\PassiveInvoice;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Customs\Exceptions\CustomsException;
use Modules\Customs\Models\CustomsImportBill;
use Modules\Customs\Models\CustomsSettings;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\GeneralLedger\Services\Tax\VatAccountResolver;

/**
 * Records an import customs bill, computes the import VAT and posts it:
 *
 *   Dr duty expense   Cr customs payable   non-taxable customs duty
 *   Dr input VAT      Cr customs payable   import VAT, with base and rate for the VAT return
 *
 * The VAT base is the customs value plus the duty plus the ancillary costs (transport and insurance to the first place
 * of destination) that are not already in the customs value.
 */
final class ImportBillService
{
    public const string SOURCE = 'customs_import_bill';

    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService,
        private readonly FiscalPeriodResolver $periods,
        private readonly VatAccountResolver $vatAccounts,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $actorId): CustomsImportBill
    {
        $base = $this->vatBase($data);
        $rate = (string) ($data['vat_rate'] ?? '0');

        return CustomsImportBill::query()->create([
            ...$data,
            'import_vat_amount' => $data['import_vat_amount'] ?? Decimal::round2(Decimal::div(Decimal::mul($base, $rate), '100')),
            'status' => CustomsImportBill::STATUS_DRAFT,
            'created_by' => $actorId,
        ]);
    }

    /**
     * Compares the bill with the invoice of the customs broker: what the broker charged beyond duty and import VAT is
     * its own fee and shows as the remainder.
     *
     * @return array{bill_total: string, supplier_total: string|null, remainder: string|null, matches: bool}
     */
    public function reconcile(CustomsImportBill $bill): array
    {
        $total = Decimal::round2(Decimal::add(Decimal::round2((string) $bill->duty_amount), Decimal::round2((string) $bill->import_vat_amount)));
        $invoice = $bill->passive_invoice_id === null ? null : PassiveInvoice::query()->find($bill->passive_invoice_id);

        if ($invoice === null) {
            return ['bill_total' => $total, 'supplier_total' => null, 'remainder' => null, 'matches' => false];
        }

        $supplierTotal = Decimal::round2((string) $invoice->total_amount);
        $remainder = Decimal::round2(Decimal::sub($supplierTotal, $total));

        return ['bill_total' => $total, 'supplier_total' => $supplierTotal, 'remainder' => $remainder, 'matches' => Decimal::isZero($remainder)];
    }

    public function post(CustomsImportBill $bill, Tenant $tenant, ?string $actorId): CustomsImportBill
    {
        if ($bill->status === CustomsImportBill::STATUS_POSTED) {
            return $bill;
        }

        $tenantId = (string) $tenant->id;

        try {
            DB::transaction(function () use ($bill, $tenant, $tenantId, $actorId): void {
                $currency = TenantAccountingCurrency::of($tenantId);

                if ($currency !== strtoupper((string) $bill->currency)) {
                    throw new InvalidArgumentException("The bill is in {$bill->currency}: the accounting currency of the company is {$currency}.");
                }

                $settings = CustomsSettings::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
                $payable = $this->ownAccount($tenantId, $settings?->customs_payable_account_id, 'customs payable');
                $lines = [];
                $duty = Decimal::round2((string) $bill->duty_amount);
                $vat = Decimal::round2((string) $bill->import_vat_amount);

                if (! Decimal::isZero($duty)) {
                    $lines[] = $this->line($this->ownAccount($tenantId, $settings?->duty_expense_account_id, 'duty expense'), $duty, '0.00', $currency);
                }

                if (! Decimal::isZero($vat)) {
                    $lines[] = $this->line($this->inputVatAccount($tenantId, (string) ($tenant->country ?: 'IT'), $settings), $vat, '0.00', $currency, [
                        'vat_base_amount' => Decimal::round2($this->vatBase($bill->toArray())),
                        'vat_tax_amount' => $vat,
                        'vat_rate_percent' => (string) $bill->vat_rate,
                    ]);
                }

                if ($lines === []) {
                    throw new InvalidArgumentException('The bill holds neither duty nor import VAT to post.');
                }

                $lines[] = $this->line($payable, '0.00', Decimal::add($duty, $vat), $currency);

                $date = $bill->bill_date->toDateString();
                $entry = $this->postingService->createAndPost([
                    'tenant_id' => $tenantId,
                    'fiscal_period_id' => $this->periods->forDate($tenantId, $bill->bill_date)->id,
                    'posting_date' => $date,
                    'document_date' => $date,
                    'description' => "Import customs bill {$bill->bill_number}",
                    'source_document_type' => self::SOURCE,
                    'source_document_id' => $bill->id,
                    'document_number' => $bill->bill_number,
                    'lines' => $lines,
                ], $actorId);

                $bill->forceFill(['status' => CustomsImportBill::STATUS_POSTED, 'journal_entry_id' => (string) $entry->id])->save();
            });
        } catch (InvalidArgumentException $e) {
            throw new CustomsException($e->getMessage());
        }

        return $bill;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function vatBase(array $data): string
    {
        return Decimal::add(
            Decimal::add((string) ($data['customs_value'] ?? '0'), (string) ($data['duty_amount'] ?? '0')),
            (string) ($data['ancillary_costs'] ?? '0'),
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function line(string $accountId, string $debit, string $credit, string $currency, array $extra = []): array
    {
        return ['account_id' => $accountId, 'debit_amount' => $debit, 'credit_amount' => $credit, 'currency' => $currency, ...$extra];
    }

    private function ownAccount(string $tenantId, ?string $accountId, string $label): string
    {
        $exists = $accountId !== null && ChartOfAccount::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($accountId)->exists();

        if (! $exists) {
            throw new InvalidArgumentException("The {$label} account is not configured in the customs settings.");
        }

        return (string) $accountId;
    }

    private function inputVatAccount(string $tenantId, string $country, ?CustomsSettings $settings): string
    {
        $inputAccounts = $this->vatAccounts->accountsFor($tenantId, $country)->inputAccountIds;
        $chosen = $settings->import_vat_account_id ?? ($inputAccounts[0] ?? null);

        if ($chosen === null || ! in_array((string) $chosen, $inputAccounts, true)) {
            throw new InvalidArgumentException('The import VAT account must be an input VAT account of the VAT settings.');
        }

        return (string) $chosen;
    }
}
