<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\MesFeedback;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Support\Money;

/**
 * Turns the piece counts of the MES Core into a PROPOSAL for the operation. The counts of a machine counter are
 * evidence, not a confirmation: they never change the quantities of the order or the stock, which move only through
 * the operation confirmations and the inventory services. A newer count replaces the pending proposal of the operation.
 */
final readonly class ProposeMesProductionCounts
{
    /**
     * Creates the pending proposal of the operation, or refreshes it when the counts are newer.
     */
    public function __invoke(string $tenantId, string $operationId, string $goodCount, string $scrapCount, string $asOfEventTime): void
    {
        $asOf = Carbon::parse($asOfEventTime)->utc();
        $values = [
            'good_count' => Money::qty($goodCount),
            'scrap_count' => Money::qty($scrapCount),
            'as_of_event_time' => $asOf,
            'updated_at' => now(),
        ];

        $pending = DB::table('mfg_mes_count_proposals')
            ->where('tenant_id', $tenantId)
            ->where('production_order_operation_id', $operationId)
            ->where('status', 'pending')
            ->lockForUpdate()
            ->first();

        if ($pending === null) {
            DB::table('mfg_mes_count_proposals')->insert($values + [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'production_order_operation_id' => $operationId,
                'status' => 'pending',
                'created_at' => now(),
            ]);

            return;
        }

        if ($asOf->greaterThanOrEqualTo(Carbon::parse((string) $pending->as_of_event_time, 'UTC'))) {
            DB::table('mfg_mes_count_proposals')->where('id', $pending->id)->update($values);
        }
    }
}
