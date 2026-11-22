<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\MesSync;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Events\MesNotificationEvent;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;

/**
 * Writes the change log the MES Core pulls reference data from, and announces changes with a lightweight outbox
 * notification. It always runs inside the transaction of the change it records, so a change is never visible without
 * its log row: `seq` is the monotonic cursor of the pull endpoint.
 */
final readonly class MesChangeRecorder
{
    public const string Machines = 'machines';

    public const string WorkOrders = 'work-orders';

    public const string ShiftCalendars = 'shift-calendars';

    public const string ReasonCodes = 'reason-codes';

    /** The resources of the pull endpoint. */
    public const array Resources = [self::Machines, self::WorkOrders, self::ShiftCalendars, self::ReasonCodes];

    public const string Upsert = 'upsert';

    public const string Delete = 'delete';

    public function __construct(private EventDispatcher $dispatcher) {}

    /**
     * Records one change and returns its sequence number.
     */
    public function record(string $tenantId, string $resource, string $entityId, string $operation = self::Upsert): int
    {
        return (int) DB::table('mfg_mes_sync_changes')->insertGetId([
            'tenant_id' => $tenantId,
            'resource' => $resource,
            'entity_id' => $entityId,
            'operation' => $operation,
            'changed_at' => now(),
        ], 'seq');
    }

    /**
     * Records the change of one order operation. Operations of a planned order are not visible to the MES Core, and
     * neither are subcontracted ones (no machine of the plant runs them): nothing is recorded for them.
     *
     * @return int|null the sequence number, null when the operation is not exposed
     */
    public function recordOperation(MfgProductionOrderOperation $operation, ?MfgProductionOrder $order = null, string $operationType = self::Upsert): ?int
    {
        $order ??= MfgProductionOrder::query()->find($operation->production_order_id);

        if ($order === null || $order->status === 'planned' || $operation->is_subcontracted) {
            return null;
        }

        return $this->record((string) $operation->tenant_id, self::WorkOrders, (string) $operation->id, $operationType);
    }

    /**
     * Records every operation of an order that is exposed to the MES Core.
     *
     * @return int|null the last sequence number, null when nothing was recorded
     */
    public function recordOrderOperations(MfgProductionOrder $order): ?int
    {
        $last = null;

        foreach (MfgProductionOrderOperation::query()->where('production_order_id', $order->id)->get() as $operation) {
            $last = $this->recordOperation($operation, $order) ?? $last;
        }

        return $last;
    }

    /**
     * Announces a change to the MES Core through the transactional outbox (the MES then pulls the entity).
     *
     * @param  class-string<MesNotificationEvent>  $eventClass
     */
    public function notify(string $eventClass, string $tenantId, string $resource, string $entityId, int $changeSeq): void
    {
        $this->dispatcher->publishAndDispatch(new $eventClass($resource, $entityId, $changeSeq, $tenantId));
    }
}
