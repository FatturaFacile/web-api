<?php

declare(strict_types=1);

namespace Modules\Pricing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Pricing\Http\Resources\PricingResource;
use Modules\Pricing\Services\PriceHistoryRecorder;

/**
 * Tenant scoped CRUD shared by the pricing records (special prices, promotions, margin policies).
 */
abstract class PricingCrudController extends Controller
{
    /**
     * @return class-string<Model>
     */
    abstract protected function modelClass(): string;

    /**
     * @return class-string<FormRequest>
     */
    abstract protected function requestClass(): string;

    /**
     * Fields of the query string that filter the list (column names).
     *
     * @return list<string>
     */
    protected function filters(): array
    {
        return [];
    }

    protected function tenantId(): string
    {
        $tenantId = app(TenantContext::class)->getTenantId() ?? request()->user()?->tenant_id;
        abort_if($tenantId === null, 403, 'Missing or unresolvable tenant context');

        return (string) $tenantId;
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', $this->modelClass());

        /** @var Builder<Model> $query */
        $query = $this->modelClass()::query()->where('tenant_id', $this->tenantId());

        foreach ($this->filters() as $column) {
            if ($request->filled($column)) {
                $query->where($column, $request->query($column));
            }
        }

        return PricingResource::collection($query->latest()->paginate(max(1, min(100, (int) $request->query('per_page', 25)))));
    }

    public function store(): JsonResponse
    {
        $this->authorize('create', $this->modelClass());
        $data = app($this->requestClass())->validated();
        $reason = $data['reason'] ?? null;
        unset($data['reason']);

        $model = app(PriceHistoryRecorder::class)->withReason($reason, fn (): Model => $this->modelClass()::query()->create([...$data, 'tenant_id' => $this->tenantId()]));

        return (new PricingResource($model->refresh()))->response()->setStatusCode(201);
    }

    public function show(string $id): PricingResource
    {
        $model = $this->modelClass()::query()->findOrFail($id);
        $this->authorize('view', $model);

        return new PricingResource($model);
    }

    public function update(string $id): PricingResource
    {
        $model = $this->modelClass()::query()->findOrFail($id);
        $this->authorize('update', $model);
        $data = app($this->requestClass())->validated();
        $reason = $data['reason'] ?? null;
        unset($data['reason']);

        app(PriceHistoryRecorder::class)->withReason($reason, static fn (): bool => $model->update($data));

        return new PricingResource($model->refresh());
    }

    public function destroy(string $id): Response
    {
        $model = $this->modelClass()::query()->findOrFail($id);
        $this->authorize('delete', $model);
        $model->delete();

        return response()->noContent();
    }
}
