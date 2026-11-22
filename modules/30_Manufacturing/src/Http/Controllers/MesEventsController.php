<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Manufacturing\Http\Requests\MesEventsRequest;
use Modules\Manufacturing\Http\Resources\MesEventsResultResource;
use Modules\Manufacturing\Services\MesFeedback\MesWireEventsProcessor;

/**
 * Receives the outbox messages the MES Core relay delivers (`OeeShiftClosed`, `OeeOrderCompleted`, `OeeSnapshotRevised`,
 * `ProductionCountCheckpoint`, `MachineAlarmRaised`). The answer is a 207 Multi-Status with a status per message.
 */
final class MesEventsController extends Controller
{
    public function __construct(private readonly MesWireEventsProcessor $processor) {}

    /**
     * Processes a batch of messages.
     */
    public function store(MesEventsRequest $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('mes_tenant_id');
        $results = [];

        foreach ($request->events() as $event) {
            $results[] = ($this->processor)(is_array($event) ? $event : [], $tenantId);
        }

        return (new MesEventsResultResource($results))->response()->setStatusCode(207);
    }
}
