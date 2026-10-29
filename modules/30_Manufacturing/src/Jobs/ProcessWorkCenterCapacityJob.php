<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Manufacturing\Models\MfgWorkCenterCapacity;

final class ProcessWorkCenterCapacityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $tenantId,
        public string $targetDate
    ) {}

    public function handle(): void
    {
        $workCenters = MfgWorkCenter::where('tenant_id', $this->tenantId)->where('status', 'active')->get();

        foreach ($workCenters as $wc) {
            $effectiveHours = $wc->calculateDailyEffectiveCapacityHours();

            // Booked hours from active production operations on this date
            $bookedMinutes = (float) MfgProductionOrderOperation::where('work_center_id', $wc->id)
                ->where('status', 'in_progress')
                ->sum('planned_run_minutes');

            $bookedHours = round($bookedMinutes / 60.0, 2);
            $utilization = $effectiveHours > 0 ? round(($bookedHours / $effectiveHours) * 100.0, 2) : 0.0;

            MfgWorkCenterCapacity::updateOrCreate(
                [
                    'tenant_id' => $this->tenantId,
                    'work_center_id' => $wc->id,
                    'calendar_date' => $this->targetDate,
                ],
                [
                    'available_hours' => $effectiveHours,
                    'booked_hours' => $bookedHours,
                    'utilization_rate' => $utilization,
                ]
            );
        }
    }
}
