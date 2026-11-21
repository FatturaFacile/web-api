<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Manufacturing\Http\Requests\MesFeedbackEventsRequest;
use Modules\Manufacturing\Http\Resources\MesFeedbackResultResource;
use Modules\Manufacturing\Services\MesFeedback\MesFeedbackProcessor;

/**
 * Receives the OEE, alarm and production-count events of the MES Core. The answer is a 207 Multi-Status: every event is
 * `applied`, `duplicate`, `stale` or `rejected` on its own.
 */
final class MesFeedbackController extends Controller
{
    public function __construct(private readonly MesFeedbackProcessor $processor) {}

    /**
     * Processes a batch of events.
     */
    public function store(MesFeedbackEventsRequest $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('mes_tenant_id');
        $results = [];

        foreach ($request->events() as $event) {
            $results[] = ($this->processor)(is_array($event) ? $event : [], $tenantId);
        }

        return (new MesFeedbackResultResource($results))->response()->setStatusCode(207);
    }
}
