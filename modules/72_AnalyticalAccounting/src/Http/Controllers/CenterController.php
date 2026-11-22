<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\AnalyticalAccounting\Http\Controllers\Concerns\InteractsWithAnalytical;
use Modules\AnalyticalAccounting\Http\Requests\CenterRequest;
use Modules\AnalyticalAccounting\Http\Resources\CenterResource;
use Modules\AnalyticalAccounting\Models\AnalyticalCenter;
use Modules\AnalyticalAccounting\Services\CenterService;

/**
 * Hierarchical cost and profit centers: paginated list, nested tree, flat ordered list, ancestors, descendants and CRUD.
 */
final class CenterController extends Controller
{
    use InteractsWithAnalytical;

    public function __construct(private readonly CenterService $centers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AnalyticalCenter::class);
        $query = AnalyticalCenter::query()->where('tenant_id', $this->tenantId())->orderBy('code');
        foreach (['center_type', 'category'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return CenterResource::collection($query->paginate($this->perPage()));
    }

    public function tree(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AnalyticalCenter::class);
        $root = $request->query('root_id');

        return response()->json(['success' => true, 'data' => $this->centers->tree($this->tenantId(), is_string($root) ? $root : null, $request->boolean('active_only'))]);
    }

    public function flat(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AnalyticalCenter::class);

        return response()->json(['success' => true, 'data' => $this->centers->flat($this->tenantId(), $request->boolean('active_only'))]);
    }

    public function ancestors(string $id): JsonResponse
    {
        $this->authorize('view', $this->find($id));

        return response()->json(['success' => true, 'data' => $this->centers->ancestors($this->tenantId(), $id)]);
    }

    public function descendants(string $id): JsonResponse
    {
        $this->authorize('view', $this->find($id));

        return response()->json(['success' => true, 'data' => $this->centers->descendants($this->tenantId(), $id)]);
    }

    public function store(CenterRequest $request): JsonResponse
    {
        $this->authorize('create', AnalyticalCenter::class);
        $center = $this->domain(fn (): AnalyticalCenter => $this->centers->create($this->tenantId(), $request->validated(), $this->userId()));

        return (new CenterResource($center))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(string $id): CenterResource
    {
        $center = $this->find($id);
        $this->authorize('view', $center);

        return new CenterResource($center);
    }

    public function update(CenterRequest $request, string $id): CenterResource
    {
        $center = $this->find($id);
        $this->authorize('update', $center);

        return new CenterResource($this->domain(fn (): AnalyticalCenter => $this->centers->update($center, $request->validated(), $this->userId())));
    }

    public function deactivate(Request $request, string $id): CenterResource
    {
        $center = $this->find($id);
        $this->authorize('update', $center);
        $this->centers->deactivate($center, $request->boolean('with_descendants'), $this->userId());

        return new CenterResource($center->refresh());
    }

    public function destroy(string $id): Response
    {
        $center = $this->find($id);
        $this->authorize('delete', $center);
        $this->domain(function () use ($center): void {
            $this->centers->delete($center);
        });

        return response()->noContent();
    }

    private function find(string $id): AnalyticalCenter
    {
        return AnalyticalCenter::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
