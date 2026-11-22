<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Infrastructure\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\MrpPlanning\Domain\Contracts\MasterScheduleGatewayInterface;
use Modules\MrpPlanning\Domain\Enums\DemandSourceType;
use Modules\MrpPlanning\Domain\ValueObjects\DemandLine;

/**
 * Reads the Mod 062 master production schedule from `mps_lines` (id, tenant_id, mfg_item_id, due_date, quantity).
 * Mod 062 is not part of this workspace yet: while the table does not exist the schedule is empty, so a run plans on
 * sales orders and safety stock alone. The adapter is the single place to change when Mod 062 lands.
 */
final class MasterScheduleGateway implements MasterScheduleGatewayInterface
{
    public function demandBetween(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('mps_lines')) {
            return [];
        }

        $rows = DB::table('mps_lines')->where('tenant_id', $tenantId)
            ->whereDate('due_date', '>=', $from->toDateString())->whereDate('due_date', '<=', $to->toDateString())
            ->get(['id', 'mfg_item_id', 'due_date', 'quantity']);

        return $rows->map(static fn (object $row): DemandLine => new DemandLine(
            DemandSourceType::MpsLine, (string) $row->id, (string) $row->mfg_item_id, CarbonImmutable::parse((string) $row->due_date), (string) $row->quantity,
        ))->all();
    }
}
