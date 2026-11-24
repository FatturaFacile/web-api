<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Privacy;

use App\Http\Requests\Privacy\BreachIncidentRequest;
use App\Http\Resources\Privacy\BreachIncidentResource;
use App\Models\Privacy\DataBreachIncident;
use App\Models\Tenant;
use App\Services\Privacy\Governance\BreachIncidentService;
use App\Services\Privacy\Governance\Queries\GetDataBreach72hCountdownHandler;
use App\Services\Privacy\Governance\Queries\GetDataBreach72hCountdownQuery;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Data breach 72 hour assistant (GDPR art. 33, nLPD art. 24).
 */
final class BreachIncidentController extends PrivacyGovernanceController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $page = DataBreachIncident::withoutGlobalScope(TenantScope::class)->where('tenant_id', $this->tenantId($request))
            ->orderByDesc('detected_at')->paginate(min(200, max(1, $request->integer('per_page', 50))));

        return BreachIncidentResource::collection($page);
    }

    public function activeCountdown(Request $request, GetDataBreach72hCountdownHandler $handler): AnonymousResourceCollection
    {
        return BreachIncidentResource::collection($handler->handle(new GetDataBreach72hCountdownQuery($this->tenantId($request))));
    }

    public function store(BreachIncidentRequest $request, BreachIncidentService $breaches): JsonResponse
    {
        $incident = $breaches->create($this->tenantId($request), $request->validated());

        return (new BreachIncidentResource($incident->refresh()))->response()->setStatusCode(201);
    }

    public function update(BreachIncidentRequest $request, BreachIncidentService $breaches, string $id): BreachIncidentResource
    {
        return new BreachIncidentResource($breaches->update($this->find($request, $id), $request->validated()));
    }

    public function dossier(Request $request, BreachIncidentService $breaches, string $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $company = (string) (Tenant::query()->whereKey($tenantId)->value('legal_name') ?? '');

        return response()->json(['data' => $breaches->dossier($this->find($request, $id), $company)]);
    }

    private function find(Request $request, string $id): DataBreachIncident
    {
        return DataBreachIncident::withoutGlobalScope(TenantScope::class)->where('tenant_id', $this->tenantId($request))->whereKey($id)->firstOrFail();
    }
}
