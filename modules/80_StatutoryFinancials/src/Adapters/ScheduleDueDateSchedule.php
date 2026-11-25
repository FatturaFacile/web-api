<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Adapters;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\StatutoryFinancials\Contracts\DueDateSchedulePort;
use Modules\StatutoryFinancials\Support\Money;

/**
 * Due-date schedule (Mod 017): open receivable items and open supplier items by maturity. The open items reflect the position
 * of the schedule when the notes are populated; secured items are not tracked by the schedule and stay editable in the notes.
 */
final class ScheduleDueDateSchedule implements DueDateSchedulePort
{
    public function maturities(string $tenantId, CarbonImmutable $asOf): array
    {
        $empty = ['within_1y' => '0', 'between_1y_5y' => '0', 'beyond_5y' => '0', 'total' => '0', 'secured' => '0'];
        $receivables = $empty;
        $payables = $empty;
        $payablesDue6m = '0';
        $available = false;

        if (Schema::hasTable('receivable_items')) {
            $available = true;

            foreach (DB::table('receivable_items')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereNotIn('status', ['paid', 'closed', 'cancelled', 'written_off'])->where('open_amount', '>', 0)->get(['open_amount', 'due_date']) as $item) {
                $receivables = $this->add($receivables, $asOf, (string) $item->due_date, Money::from($item->open_amount));
            }
        }

        if (Schema::hasTable('ap_open_items')) {
            $available = true;
            $limit = $asOf->addMonths(6);

            foreach (DB::table('ap_open_items')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereNotIn('status', ['paid', 'closed', 'cancelled'])->where('open_amount', '>', 0)->get(['open_amount', 'due_date']) as $item) {
                $amount = Money::from($item->open_amount);
                $payables = $this->add($payables, $asOf, (string) $item->due_date, $amount);

                if (CarbonImmutable::parse($item->due_date)->lessThanOrEqualTo($limit)) {
                    $payablesDue6m = Money::add($payablesDue6m, $amount);
                }
            }
        }

        return ['available' => $available, 'source' => 'Mod 017', 'receivables' => $receivables, 'payables' => $payables, 'payables_due_6m' => $payablesDue6m];
    }

    /**
     * @param  array<string, string>  $buckets
     * @return array<string, string>
     */
    private function add(array $buckets, CarbonImmutable $asOf, string $dueDate, string $amount): array
    {
        $due = CarbonImmutable::parse($dueDate);
        $bucket = match (true) {
            $due->lessThanOrEqualTo($asOf->addYear()) => 'within_1y',
            $due->lessThanOrEqualTo($asOf->addYears(5)) => 'between_1y_5y',
            default => 'beyond_5y',
        };
        $buckets[$bucket] = Money::add($buckets[$bucket], $amount);
        $buckets['total'] = Money::add($buckets['total'], $amount);

        return $buckets;
    }
}
