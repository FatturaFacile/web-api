<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Adapters;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\StatutoryFinancials\Contracts\LeaseRegisterPort;
use Modules\StatutoryFinancials\Support\Money;

/**
 * Lease register of Mod 147 (lease accounting): the schedule lines give the payments still due by maturity, the liability at the end
 * of the year and the interest of the year; the purchase option price is the redemption value.
 */
final class LeaseAccountingLeaseRegister implements LeaseRegisterPort
{
    public function commitments(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('lease_contracts') || ! Schema::hasTable('lease_schedule_lines')) {
            return ['available' => false, 'source' => 'Mod 147', 'rows' => [], 'due_6m' => '0'];
        }

        $contracts = DB::table('lease_contracts')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereNotIn('status', ['draft', 'cancelled'])->get(['id', 'number', 'title', 'lessor_name', 'asset_class', 'purchase_option_price', 'currency']);
        $lines = DB::table('lease_schedule_lines')->where('tenant_id', $tenantId)->whereNull('deleted_at')->get(['lease_contract_id', 'period_start', 'period_end', 'payment', 'interest', 'depreciation', 'closing_liability'])->groupBy('lease_contract_id');
        $rows = [];
        $due6m = '0';

        foreach ($contracts as $contract) {
            $schedule = $lines->get($contract->id, collect());
            $row = ['number' => $contract->number, 'title' => $contract->title, 'lessor' => $contract->lessor_name, 'asset_class' => $contract->asset_class, 'within_1y' => '0', 'between_1y_5y' => '0', 'beyond_5y' => '0', 'total_commitment' => '0', 'closing_liability' => '0', 'interest_year' => '0', 'depreciation_year' => '0', 'redemption_value' => Money::from($contract->purchase_option_price)];
            $closing = null;

            foreach ($schedule->sortBy('period_start') as $line) {
                $start = CarbonImmutable::parse($line->period_start);
                $end = CarbonImmutable::parse($line->period_end);

                if ($end->lessThanOrEqualTo($to) && $end->greaterThanOrEqualTo($from)) {
                    $row['interest_year'] = Money::add($row['interest_year'], Money::from($line->interest));
                    $row['depreciation_year'] = Money::add($row['depreciation_year'], Money::from($line->depreciation));
                }

                if ($end->lessThanOrEqualTo($to)) {
                    $closing = Money::from($line->closing_liability);

                    continue;
                }

                $payment = Money::from($line->payment);
                $bucket = match (true) {
                    $start->lessThanOrEqualTo($to->addYear()) => 'within_1y',
                    $start->lessThanOrEqualTo($to->addYears(5)) => 'between_1y_5y',
                    default => 'beyond_5y',
                };
                $row[$bucket] = Money::add($row[$bucket], $payment);
                $row['total_commitment'] = Money::add($row['total_commitment'], $payment);

                if ($start->lessThanOrEqualTo($to->addMonths(6))) {
                    $due6m = Money::add($due6m, $payment);
                }
            }

            $row['closing_liability'] = $closing ?? '0';
            $rows[] = $row;
        }

        return ['available' => true, 'source' => 'Mod 147', 'rows' => $rows, 'due_6m' => $due6m];
    }
}
