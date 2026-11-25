<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\FranchiseNetwork\Domain\Enums\RunStatus;
use Modules\FranchiseNetwork\Domain\Enums\StatementStatus;
use Modules\FranchiseNetwork\Domain\Enums\StoreStatus;
use Modules\FranchiseNetwork\Domain\Enums\StoreType;
use Modules\FranchiseNetwork\Domain\Support\Dec;
use Modules\FranchiseNetwork\Domain\Support\LoyaltySettlementCalculator;
use Modules\FranchiseNetwork\Domain\Support\RoyaltyCalculator;
use Modules\FranchiseNetwork\Events\RoyaltyRunInvoiced;
use Modules\FranchiseNetwork\Exceptions\FranchiseNetworkException;
use Modules\FranchiseNetwork\Models\FranchiseStore;
use Modules\FranchiseNetwork\Models\LoyaltyTransaction;
use Modules\FranchiseNetwork\Models\RoyaltyRun;
use Modules\FranchiseNetwork\Models\RoyaltyStatement;
use Modules\FranchiseNetwork\Models\SellOutReport;
use Modules\Invoicing\Services\InvoicingService;

/**
 * Monthly billing engine of the franchisor.
 *
 * calculate(): one statement per active store from its net sell-out: progressive royalty and marketing fund, start-up exemption,
 * minimum guarantee and the net loyalty settlement between the stores. A store that cannot be billed (directly operated, no agreement
 * in force, no formula, no sell-out reported) gets a skipped statement with the reason. Safe to repeat until the run is invoiced.
 * invoice(): turns the statements into sales invoices of the franchisor (royalty, minimum guarantee adjustment, marketing fund,
 * loyalty settlement) and prepares the SEPA SDD / LSV+ collection batches.
 */
final class RoyaltyBillingService
{
    public function __construct(
        private readonly PurchaseCommitmentService $agreements,
        private readonly FormulaService $formulas,
        private readonly LoyaltyLedgerService $loyalty,
        private readonly InvoicingService $invoicing,
        private readonly CollectionBatchService $collections,
        private readonly NetworkAccess $access,
    ) {}

    public function calculate(string $period, ?string $userId): RoyaltyRun
    {
        BillingPeriod::assert($period);
        $tenant = $this->access->tenant();

        return DB::transaction(function () use ($period, $userId, $tenant): RoyaltyRun {
            $run = RoyaltyRun::query()->where('period', $period)->lockForUpdate()->first();

            if ($run !== null && $run->status === RunStatus::Invoiced) {
                throw new FranchiseNetworkException('run_already_invoiced', 409);
            }

            if ($run === null) {
                $run = RoyaltyRun::query()->create(['period' => $period, 'currency' => $tenant->default_currency ?? 'EUR', 'created_by' => $userId]);
            } else {
                LoyaltyTransaction::query()->where('settled_run_id', $run->id)->update(['settled_run_id' => null]);
                RoyaltyStatement::query()->where('run_id', $run->id)->forceDelete();
            }

            $drafts = $this->draftStatements($period);
            $billable = array_keys(array_filter($drafts, static fn (array $draft): bool => $draft['status'] === StatementStatus::Calculated->value));
            [$positions, $settledIds] = $this->settleLoyalty($period, $drafts, $billable);

            foreach ($drafts as $storeId => $draft) {
                $loyalty = $draft['status'] === StatementStatus::Calculated->value ? ($positions[$storeId]['net'] ?? '0.00') : '0.00';
                $draft['loyalty_settlement'] = $loyalty;
                $draft['total_net'] = Dec::round(Dec::add($draft['royalty_charged'], Dec::add($draft['marketing_fund'], $loyalty)));
                $draft['breakdown'] = [...($draft['breakdown'] ?? []), 'loyalty' => $positions[$storeId] ?? ['owed' => '0.00', 'receivable' => '0.00', 'net' => '0.00']];

                RoyaltyStatement::query()->create([...$draft, 'run_id' => $run->id, 'store_id' => $storeId, 'period' => $period, 'created_by' => $userId]);
            }

            if ($settledIds !== []) {
                LoyaltyTransaction::query()->whereIn('id', $settledIds)->update(['settled_run_id' => $run->id]);
            }

            $statements = RoyaltyStatement::query()->where('run_id', $run->id)->where('status', StatementStatus::Calculated->value)->get();
            $run->update([
                'status' => RunStatus::Calculated->value,
                'stores_count' => $statements->count(),
                'total_royalty' => Dec::round($statements->reduce(static fn (string $sum, RoyaltyStatement $row): string => Dec::add($sum, $row->royalty_after_startup), '0')),
                'total_marketing' => Dec::round($statements->reduce(static fn (string $sum, RoyaltyStatement $row): string => Dec::add($sum, $row->marketing_fund), '0')),
                'total_minimum_adjustment' => Dec::round($statements->reduce(static fn (string $sum, RoyaltyStatement $row): string => Dec::add($sum, $row->minimum_adjustment), '0')),
                'total_loyalty_settlement' => Dec::round($statements->reduce(static fn (string $sum, RoyaltyStatement $row): string => Dec::add($sum, $row->loyalty_settlement), '0')),
                'total_net' => Dec::round($statements->reduce(static fn (string $sum, RoyaltyStatement $row): string => Dec::add($sum, $row->total_net), '0')),
                'calculated_at' => now(),
                'updated_by' => $userId,
            ]);

            return $run->refresh();
        });
    }

    public function invoice(RoyaltyRun $run, ?string $userId): RoyaltyRun
    {
        if ($run->status === RunStatus::Invoiced) {
            throw new FranchiseNetworkException('run_already_invoiced', 409);
        }

        $statements = RoyaltyStatement::query()->with('store')->where('run_id', $run->id)->where('status', StatementStatus::Calculated->value)->get();
        $missing = $statements->filter(static fn (RoyaltyStatement $row): bool => $row->store?->contact_id === null && Dec::cmp($row->total_net, 0) !== 0);

        if ($missing->isNotEmpty()) {
            throw new FranchiseNetworkException('store_without_customer', 422, ['stores' => $missing->map(static fn (RoyaltyStatement $row): string => (string) $row->store?->code)->implode(', ')]);
        }

        $tenant = $this->access->tenant();
        $vatRate = (float) ($tenant->country === 'CH' ? config('franchise_network.billing.ch_vat_rate') : config('franchise_network.billing.it_vat_rate'));

        DB::transaction(function () use ($run, $statements, $tenant, $vatRate, $userId): void {
            foreach ($statements as $statement) {
                if (Dec::cmp($statement->total_net, 0) <= 0) {
                    $statement->update(['status' => StatementStatus::Skipped->value, 'skip_reason' => 'zero_amount']);

                    continue;
                }

                $invoice = $this->invoicing->createInvoice([
                    'tenant_id' => $tenant->id,
                    'contact_id' => $statement->store->contact_id,
                    'invoice_type' => 'standard',
                    'currency' => $run->currency,
                    'jurisdiction' => $tenant->country === 'CH' ? 'CH' : 'IT',
                    'due_date' => CarbonImmutable::today()->addDays((int) config('franchise_network.billing.payment_days'))->toDateString(),
                    'notes' => (string) __('franchise_network.invoice.notes', ['period' => $run->period, 'store' => $statement->store->code]),
                    'created_by' => $userId,
                ], $this->lines($statement, $vatRate));

                $statement->update(['status' => StatementStatus::Invoiced->value, 'invoice_id' => $invoice->id, 'updated_by' => $userId]);
            }

            $run->update(['status' => RunStatus::Invoiced->value, 'invoiced_at' => now(), 'updated_by' => $userId]);
            $this->collections->build($run->refresh());
        });

        event(new RoyaltyRunInvoiced((string) $run->tenant_id, (string) $run->id, $run->period));

        return $run->refresh();
    }

    /**
     * Statements of the period for every active store, before the loyalty settlement.
     *
     * @return array<string, array<string, mixed>> by store id
     */
    private function draftStatements(string $period): array
    {
        $drafts = [];
        $reports = SellOutReport::query()->where('period', $period)->get()->keyBy('store_id');

        foreach (FranchiseStore::query()->where('status', StoreStatus::Active->value)->orderBy('code')->get() as $store) {
            $agreement = $store->store_type === StoreType::Franchisee ? $this->agreements->agreementFor($store, $period) : null;
            $report = $reports->get($store->id);
            $skip = match (true) {
                $store->store_type === StoreType::DirectlyOperated => 'directly_operated',
                $agreement === null => 'no_active_agreement',
                $agreement->formula === null => 'no_formula',
                $report === null => 'no_sell_out',
                default => null,
            };

            $zero = ['net_sales' => '0.00', 'royalty_gross' => '0.00', 'startup_factor' => '1.000000', 'royalty_after_startup' => '0.00', 'minimum_guarantee_applied' => '0.00', 'minimum_adjustment' => '0.00', 'royalty_charged' => '0.00', 'marketing_fund' => '0.00'];

            if ($skip !== null) {
                $drafts[(string) $store->id] = [...$zero, 'status' => StatementStatus::Skipped->value, 'skip_reason' => $skip, 'agreement_id' => $agreement?->id, 'formula_id' => $agreement?->formula_id];

                continue;
            }

            $figures = RoyaltyCalculator::calculate($report->net_sales, $this->formulas->asArray($agreement->formula), $store->opened_on?->toImmutable(), $period);
            unset($figures['startup_month']);
            $drafts[(string) $store->id] = [...$figures, 'status' => StatementStatus::Calculated->value, 'skip_reason' => null, 'agreement_id' => $agreement->id, 'formula_id' => $agreement->formula_id];
        }

        return $drafts;
    }

    /**
     * Net loyalty positions of the billable stores. A store whose credit would exceed what it owes the network in royalty and fund
     * (the invoice would be negative) is left out with every redemption it earned: those redemptions carry over, for both sides.
     *
     * @param  array<string, array<string, mixed>>  $drafts
     * @param  list<string>  $billable
     * @return array{0: array<string, array{owed: string, receivable: string, net: string}>, 1: list<string>}
     */
    private function settleLoyalty(string $period, array $drafts, array $billable): array
    {
        $rows = array_values(array_filter(
            $this->loyalty->openRedemptions($period),
            static fn (array $row): bool => $row['earned_store_id'] !== null && $row['earned_store_id'] !== $row['store_id'] && in_array($row['store_id'], $billable, true) && in_array($row['earned_store_id'], $billable, true),
        ));

        while (true) {
            $positions = LoyaltySettlementCalculator::settle($rows);
            $negative = [];

            foreach ($positions as $storeId => $position) {
                $base = Dec::add($drafts[$storeId]['royalty_charged'], $drafts[$storeId]['marketing_fund']);

                if (Dec::cmp(Dec::add($base, $position['net']), 0) < 0) {
                    $negative[] = $storeId;
                }
            }

            if ($negative === []) {
                return [$positions, array_column($rows, 'id')];
            }

            $rows = array_values(array_filter($rows, static fn (array $row): bool => ! in_array($row['earned_store_id'], $negative, true)));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(RoyaltyStatement $statement, float $vatRate): array
    {
        $params = ['period' => $statement->period, 'sales' => $statement->net_sales, 'store' => $statement->store->code];
        $lines = [];

        if (Dec::cmp($statement->royalty_after_startup, 0) > 0) {
            $lines[] = ['description' => (string) __('franchise_network.invoice.line_royalty', $params), 'quantity' => 1, 'unit_price' => $statement->royalty_after_startup, 'tax_rate' => $vatRate];
        }

        if (Dec::cmp($statement->minimum_adjustment, 0) > 0) {
            $lines[] = ['description' => (string) __('franchise_network.invoice.line_minimum_guarantee', [...$params, 'amount' => $statement->minimum_guarantee_applied]), 'quantity' => 1, 'unit_price' => $statement->minimum_adjustment, 'tax_rate' => $vatRate];
        }

        if (Dec::cmp($statement->marketing_fund, 0) > 0) {
            $lines[] = ['description' => (string) __('franchise_network.invoice.line_marketing_fund', $params), 'quantity' => 1, 'unit_price' => $statement->marketing_fund, 'tax_rate' => $vatRate];
        }

        if (Dec::cmp($statement->loyalty_settlement, 0) !== 0) {
            $key = Dec::cmp($statement->loyalty_settlement, 0) > 0 ? 'line_loyalty_debit' : 'line_loyalty_credit';
            $lines[] = ['description' => (string) __('franchise_network.invoice.'.$key, $params), 'quantity' => 1, 'unit_price' => $statement->loyalty_settlement, 'tax_rate' => $vatRate];
        }

        return $lines;
    }
}
