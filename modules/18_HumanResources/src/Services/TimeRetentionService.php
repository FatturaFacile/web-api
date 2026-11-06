<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\HumanResources\Models\TimeAuditEntry;
use Modules\HumanResources\Services\TimeTracking\DataRetentionPolicy;
use Modules\HumanResources\Services\TimeTracking\DataRetentionResult;

/**
 * Deletes the working time records whose retention period (art. 73 cpv. 2 OLL 1) has expired. The deletion is done in
 * the database directly, also for the events already deleted logically, because the application only allows logical
 * deletion of these records; it is itself recorded in the history. Run it inside the context of the tenant.
 */
final class TimeRetentionService
{
    public function purge(Tenant $tenant, CarbonImmutable $today, int $configuredYears): DataRetentionResult
    {
        $cutoff = DataRetentionPolicy::cutoff($today, $configuredYears);
        $cutoffInstant = $cutoff->utc()->format('Y-m-d H:i:s');

        return DB::transaction(function () use ($tenant, $cutoff, $cutoffInstant): DataRetentionResult {
            $clockEvents = DB::table('time_clock_events')
                ->where('tenant_id', $tenant->id)
                ->where('occurred_at', '<', $cutoffInstant)
                ->delete();

            $dailyRecords = DB::table('time_daily_records')
                ->where('tenant_id', $tenant->id)
                ->where('work_date', '<', $cutoff->format('Y-m-d'))
                ->delete();

            $auditEntries = $this->purgeAuditEntries((string) $tenant->id, $cutoffInstant);

            $result = new DataRetentionResult((string) $tenant->id, $cutoff, $clockEvents, $dailyRecords, $auditEntries);

            if ($result->total() > 0) {
                TimeAuditEntry::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => null,
                    'entity_type' => 'Retention',
                    'entity_id' => null,
                    'action' => 'RetentionPurge',
                    'after_values' => [
                        'cutoff' => $cutoff->format('Y-m-d'),
                        'clock_events' => $clockEvents,
                        'daily_records' => $dailyRecords,
                        'audit_entries' => $auditEntries,
                    ],
                    'reason' => 'Termine di conservazione scaduto (art. 73 cpv. 2 OLL 1)',
                    'actor_id' => null,
                    'actor_name' => 'Sistema',
                    'occurred_at' => CarbonImmutable::now(),
                ]);
            }

            return $result;
        });
    }

    /**
     * The PostgreSQL trigger lets a deletion through only with this setting, local to the transaction.
     */
    private function purgeAuditEntries(string $tenantId, string $cutoffInstant): int
    {
        $usesTrigger = DB::getDriverName() === 'pgsql';

        if ($usesTrigger) {
            DB::select("SELECT set_config('app.time_audit_purge', 'on', true)");
        }

        $deleted = DB::table('time_audit_entries')
            ->where('tenant_id', $tenantId)
            ->where('occurred_at', '<', $cutoffInstant)
            ->delete();

        if ($usesTrigger) {
            DB::select("SELECT set_config('app.time_audit_purge', 'off', true)");
        }

        return $deleted;
    }
}
