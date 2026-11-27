<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Distribution\Domain\Enums\BucketType;
use Modules\Distribution\Http\Controllers\Concerns\InteractsWithDistribution;
use Modules\Distribution\Http\Requests\DistributionFilterRequest;
use Modules\Distribution\Http\Requests\RunDrpRequest;
use Modules\Distribution\Http\Resources\ArrayResource;
use Modules\Distribution\Http\Resources\DrpRunResource;
use Modules\Distribution\Models\DrpAlert;
use Modules\Distribution\Models\DrpPlanLine;
use Modules\Distribution\Models\DrpRun;
use Modules\Distribution\Services\DrpPlanner;
use OpenApi\Attributes as OA;

/**
 * Time-phased DRP: run the planning, list the versioned runs, read the grid of a run and its alerts.
 */
final class DrpController extends Controller
{
    use ApiResponse;
    use InteractsWithDistribution;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Post(path: '/api/v1/distribution/drp/runs', operationId: 'distributionRunDrp', summary: 'Run the DRP planning and store the snapshot', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 201, description: 'DRP run completed')])]
    public function run(RunDrpRequest $request, DrpPlanner $planner): JsonResponse
    {
        Gate::authorize('create', DrpRun::class);

        $run = $planner->run(
            BucketType::from((string) $request->input('bucket_type')),
            (int) $request->input('horizon'),
            array_values((array) $request->input('warehouse_ids', [])),
            array_values((array) $request->input('stock_item_ids', [])),
            $this->userId(),
        );

        return $this->createdResponse(new DrpRunResource($run->load(['alerts.warehouse', 'alerts.item'])), $this->message('drp_completed'));
    }

    #[OA\Get(path: '/api/v1/distribution/drp/runs', operationId: 'distributionListDrpRuns', summary: 'List the DRP runs', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Runs, newest first')])]
    public function runs(): JsonResponse
    {
        Gate::authorize('viewAny', DrpRun::class);

        return $this->paged(DrpRun::query()->orderByDesc('started_at')->paginate($this->perPage(25)), DrpRunResource::class);
    }

    #[OA\Get(path: '/api/v1/distribution/drp/runs/{id}', operationId: 'distributionShowDrpRun', summary: 'Get a DRP run with its alerts', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Run')])]
    public function show(string $id): JsonResponse
    {
        $run = DrpRun::query()->with(['alerts.warehouse', 'alerts.item'])->findOrFail($id);
        Gate::authorize('view', $run);

        return $this->successResponse(new DrpRunResource($run), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/distribution/drp/runs/{id}/grid', operationId: 'distributionDrpGrid', summary: 'The time-phased grid of a DRP run', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Buckets and rows')])]
    public function grid(string $id, DistributionFilterRequest $request): JsonResponse
    {
        $run = DrpRun::query()->findOrFail($id);
        Gate::authorize('view', $run);

        $lines = DrpPlanLine::query()
            ->where('drp_run_id', $run->id)
            ->with(['warehouse', 'item'])
            ->when($request->input('warehouse_id'), static fn ($rows, $warehouse) => $rows->where('warehouse_id', $warehouse))
            ->when($request->input('stock_item_id'), static fn ($rows, $item) => $rows->where('stock_item_id', $item))
            ->get();

        $rows = $lines->map(static fn (DrpPlanLine $line): array => [
            'warehouse_id' => (string) $line->warehouse_id,
            'stock_item_id' => (string) $line->stock_item_id,
            'warehouse' => ['id' => (string) $line->warehouse?->id, 'code' => (string) $line->warehouse?->code, 'name' => (string) $line->warehouse?->name],
            'item' => ['id' => (string) $line->item?->id, 'sku' => (string) $line->item?->sku, 'name' => (string) $line->item?->name, 'uom' => (string) $line->item?->uom],
            'lead_time_days' => $line->lead_time_days,
            'source_warehouse_id' => $line->source_warehouse_id,
            'opening_balance' => $line->opening_balance,
            'safety_stock' => $line->safety_stock,
            'cells' => $line->cells,
        ])->values()->all();

        return $this->successResponse(new ArrayResource(['buckets' => $run->summary['buckets'] ?? [], 'rows' => $rows]), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/distribution/drp/alerts', operationId: 'distributionDrpAlerts', summary: 'The alerts of a DRP run (the latest one by default)', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Alerts')])]
    public function alerts(DistributionFilterRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', DrpAlert::class);

        $runId = $request->input('run_id') ?? DrpRun::query()->where('status', 'completed')->orderByDesc('started_at')->value('id');
        $alerts = DrpAlert::query()->where('drp_run_id', $runId)->with(['warehouse', 'item'])->orderBy('severity')->get();

        return $this->successResponse($alerts->map(static fn (DrpAlert $alert): array => [
            'id' => (string) $alert->id,
            'type' => $alert->alert_type->value,
            'severity' => $alert->severity,
            'warehouse_id' => $alert->warehouse_id,
            'stock_item_id' => $alert->stock_item_id,
            'bucket_index' => $alert->bucket_index,
            'transfer_order_id' => $alert->transfer_order_id,
            'warehouse' => $alert->warehouse === null ? null : ['id' => (string) $alert->warehouse->id, 'code' => (string) $alert->warehouse->code, 'name' => (string) $alert->warehouse->name],
            'item' => $alert->item === null ? null : ['id' => (string) $alert->item->id, 'sku' => (string) $alert->item->sku, 'name' => (string) $alert->item->name, 'uom' => (string) $alert->item->uom],
            'params' => $alert->params ?? [],
        ])->values()->all(), $this->message('listed'));
    }
}
