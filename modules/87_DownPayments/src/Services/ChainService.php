<?php

declare(strict_types=1);

namespace Modules\DownPayments\Services;

use App\Services\Sales\DocumentNumberingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\DownPayments\Domain\Enums\ChainStatus;
use Modules\DownPayments\Domain\Enums\DepositType;
use Modules\DownPayments\Domain\Enums\InstallmentStatus;
use Modules\DownPayments\Domain\Enums\InstrumentType;
use Modules\DownPayments\Domain\Enums\SourceType;
use Modules\DownPayments\Exceptions\DownPaymentException;
use Modules\DownPayments\Models\DownPaymentChain;
use Modules\DownPayments\Models\DownPaymentInstallment;
use Modules\DownPayments\Models\DownPaymentLink;
use Modules\DownPayments\Models\EarnestAndSecurityDeposit;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Opens a chain from a sales order, quote or contract, runs the installment plan wizard, and emits the installments of the plan
 * with the instrument each states (advance invoice, pro-forma request, earnest money receipt, security deposit receipt).
 */
final class ChainService
{
    public function __construct(
        private readonly ChainSourceResolver $sources,
        private readonly InstallmentPlanService $plan,
        private readonly ChainLedger $ledger,
        private readonly AdvanceEmissionService $advances,
        private readonly DepositService $deposits,
        private readonly DocumentNumberingService $numbering,
    ) {}

    /**
     * The plan the wizard would build for a source, without storing anything: the installments with their split over the VAT
     * rates and the checks of the plan (a plan never states more than the source holds).
     *
     * @param  array{source_type: string, source_id: string, template?: ?string, installments?: list<array<string, mixed>>, contract?: array<string, mixed>}  $data
     * @return array<string, mixed>
     */
    public function previewPlan(string $tenantId, array $data): array
    {
        $source = $this->sources->resolve($tenantId, SourceType::from($data['source_type']), $data['source_id'], (array) ($data['contract'] ?? []));
        $rows = $this->plan->compute($source->bases, $this->installmentInputs($data));
        $contractGross = $this->plan->grossOf($source->bases);

        return [
            'source' => ['type' => $source->type, 'id' => $source->id, 'number' => $source->number, 'customer_id' => $source->customerId, 'customer_name' => $source->customerName, 'currency' => $source->currency, 'jurisdiction' => $source->jurisdiction],
            'contract_net' => Decimal::round2(array_reduce($source->bases, static fn (string $sum, string $net): string => Decimal::add($sum, $net), '0')),
            'contract_gross' => $contractGross,
            'vat_bases' => $source->bases,
            'installments' => $rows,
            'planned_gross' => Decimal::round2(array_reduce($rows, static fn (string $sum, array $row): string => $row['instrument_type'] === InstrumentType::SecurityDeposit->value ? $sum : Decimal::add($sum, $row['amount_gross']), '0')),
            'rounding_drift' => Decimal::round2(array_reduce($rows, static fn (string $sum, array $row): string => Decimal::add($sum, $row['rounding_drift']), '0')),
            'presets' => array_keys($this->plan->presets()),
        ];
    }

    /**
     * @param  array{source_type: string, source_id: string, template?: ?string, installments?: list<array<string, mixed>>, contract?: array<string, mixed>, notes?: ?string}  $data
     *
     * @throws DownPaymentException when the source already has a chain, has nothing to bill or the plan is not valid
     */
    public function create(string $tenantId, array $data, string $userId): DownPaymentChain
    {
        return DB::transaction(function () use ($tenantId, $data, $userId): DownPaymentChain {
            $type = SourceType::from($data['source_type']);
            $source = $this->sources->resolve($tenantId, $type, $data['source_id'], (array) ($data['contract'] ?? []));

            $existing = DownPaymentChain::query()->where('tenant_id', $tenantId)->where('source_type', $type->value)->where('source_id', $source->id)->where('status', '!=', ChainStatus::Cancelled->value)->exists();

            if ($existing) {
                throw new DownPaymentException('chain_exists_for_source', 409);
            }

            $inputs = $this->installmentInputs($data);
            $rows = $inputs === [] ? [] : $this->plan->compute($source->bases, $inputs);

            $chain = DownPaymentChain::query()->create([
                'tenant_id' => $tenantId,
                'chain_number' => $this->numbering->next('down_payment_chain', null, $tenantId)['number'],
                'source_type' => $type->value,
                'source_id' => $source->id,
                'source_number' => $source->number,
                'sales_order_id' => $source->salesOrderId,
                'customer_id' => $source->customerId,
                'customer_name' => $source->customerName,
                'currency' => $source->currency,
                'jurisdiction' => $source->jurisdiction,
                'status' => ChainStatus::Draft->value,
                'vat_bases' => $source->bases,
                'supply_lines' => $source->supplyLines,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($rows as $row) {
                $this->storeInstallment($chain, $row);
            }

            return $this->ledger->refresh($chain)->load(['installments']);
        });
    }

    /**
     * Cancels a chain that has issued nothing yet.
     *
     * @throws DownPaymentException when a document or deposit was already issued
     */
    public function cancel(DownPaymentChain $chain): DownPaymentChain
    {
        return DB::transaction(function () use ($chain): DownPaymentChain {
            if (DownPaymentLink::query()->where('chain_id', $chain->id)->exists() || EarnestAndSecurityDeposit::query()->where('chain_id', $chain->id)->exists()) {
                throw new DownPaymentException('chain_has_documents');
            }

            $chain->forceFill(['status' => ChainStatus::Cancelled->value])->save();
            DownPaymentInstallment::query()->where('chain_id', $chain->id)->update(['status' => InstallmentStatus::Cancelled->value]);

            return $chain->refresh();
        });
    }

    /**
     * Emits an installment with its instrument.
     *
     * @param  array{date?: ?string, due_date?: ?string, payment_date?: ?string, payment_account?: ?string}  $options
     * @return array{instrument: string, link_id: string|null, document_id: string|null, deposit_id: string|null}
     *
     * @throws DownPaymentException when the installment was already emitted or the chain is closed
     */
    public function emit(DownPaymentChain $chain, DownPaymentInstallment $installment, array $options, string $userId): array
    {
        return DB::transaction(function () use ($chain, $installment, $options, $userId): array {
            $locked = DownPaymentChain::query()->where('tenant_id', $chain->tenant_id)->whereKey($chain->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [ChainStatus::Settled->value, ChainStatus::Cancelled->value, ChainStatus::Forfeited->value], true)) {
                throw new DownPaymentException('chain_closed');
            }

            $row = DownPaymentInstallment::query()->where('chain_id', $locked->id)->whereKey($installment->id)->lockForUpdate()->firstOrFail();

            if ($row->status !== InstallmentStatus::Planned->value) {
                throw new DownPaymentException('installment_not_planned', 422, ['status' => (string) $row->status]);
            }

            $instrument = InstrumentType::from($row->instrument_type);
            $date = $options['date'] ?? CarbonImmutable::now()->toDateString();
            $due = $options['due_date'] ?? ($row->due_date?->toDateString());

            if ($instrument === InstrumentType::EarnestMoneyDeposit || $instrument === InstrumentType::SecurityDeposit) {
                $deposit = $this->deposits->issueReceipt($locked, $row, [
                    'deposit_type' => ($instrument === InstrumentType::EarnestMoneyDeposit ? DepositType::EarnestMoney : DepositType::SecurityDeposit)->value,
                    'amount' => (string) $row->amount_gross,
                    'issue_date' => $date,
                    'payment_date' => $options['payment_date'] ?? null,
                    'payment_account' => $options['payment_account'] ?? null,
                ], $userId);

                return ['instrument' => $instrument->value, 'link_id' => null, 'document_id' => (string) $deposit->id, 'deposit_id' => (string) $deposit->id];
            }

            $slices = $this->advances->slicesFor($locked, $row);
            $link = $instrument === InstrumentType::AdvanceProformaRequest
                ? $this->advances->requestProforma($locked, $row, $slices, ['date' => $date, 'due_date' => $due], $userId)
                : $this->advances->issueAdvance($locked, $row, $slices, ['date' => $date, 'due_date' => $due], $userId);

            return ['instrument' => $instrument->value, 'link_id' => (string) $link->id, 'document_id' => $link->document_id, 'deposit_id' => null];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function installmentInputs(array $data): array
    {
        if (! empty($data['installments'])) {
            return array_values($data['installments']);
        }

        if (! empty($data['template'])) {
            return $this->plan->presets()[$data['template']] ?? throw new DownPaymentException('plan_template_unknown');
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function storeInstallment(DownPaymentChain $chain, array $row): DownPaymentInstallment
    {
        return DownPaymentInstallment::query()->create([
            'tenant_id' => (string) $chain->tenant_id,
            'chain_id' => (string) $chain->id,
            'sequence' => $row['sequence'],
            'milestone_label' => $row['milestone_label'],
            'trigger_type' => $row['trigger_type'],
            'due_date' => $row['due_date'],
            'calculation_mode' => $row['calculation_mode'],
            'percentage' => $row['percentage'],
            'amount_net' => $row['amount_net'],
            'amount_vat' => $row['amount_vat'],
            'amount_gross' => $row['amount_gross'],
            'instrument_type' => $row['instrument_type'],
            'status' => InstallmentStatus::Planned->value,
            'vat_breakdown' => $row['vat_breakdown'],
        ]);
    }
}
