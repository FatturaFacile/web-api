<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Modules\AnalyticalAccounting\Http\Controllers\Concerns\InteractsWithAnalytical;
use Modules\AnalyticalAccounting\Http\Requests\DriverRequest;
use Modules\AnalyticalAccounting\Http\Requests\DriverValuesRequest;
use Modules\AnalyticalAccounting\Http\Resources\DriverResource;
use Modules\AnalyticalAccounting\Http\Resources\DriverValueResource;
use Modules\AnalyticalAccounting\Models\AllocationDriver;
use Modules\AnalyticalAccounting\Services\DriverValueService;

/**
 * Allocation drivers (area, headcount, machine hours, revenue, fixed percentage and custom numeric keys) and their values
 * per center and period.
 */
final class DriverController extends Controller
{
    use InteractsWithAnalytical;

    public function __construct(private readonly DriverValueService $driverValues) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AllocationDriver::class);
        $this->driverValues->ensureSystemDrivers($this->tenantId());

        return DriverResource::collection(AllocationDriver::query()->where('tenant_id', $this->tenantId())->orderBy('code')->paginate($this->perPage()));
    }

    public function store(DriverRequest $request): JsonResponse
    {
        $this->authorize('create', AllocationDriver::class);
        $driver = AllocationDriver::create(['tenant_id' => $this->tenantId(), 'is_system' => false, 'is_active' => true, 'created_by' => $this->userId(), ...$request->validated()]);

        return (new DriverResource($driver))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(DriverRequest $request, string $id): DriverResource
    {
        $driver = $this->find($id);
        $this->authorize('update', $driver);
        $data = $request->validated();
        if ($driver->is_system) {
            unset($data['code'], $data['driver_type']);
        }
        $driver->fill([...$data, 'updated_by' => $this->userId()])->save();

        return new DriverResource($driver);
    }

    public function destroy(string $id): Response
    {
        $driver = $this->find($id);
        $this->authorize('delete', $driver);
        if ($driver->is_system) {
            throw ValidationException::withMessages(['request' => ['A system driver cannot be deleted.']]);
        }
        $driver->delete();

        return response()->noContent();
    }

    public function values(Request $request, string $id): AnonymousResourceCollection
    {
        $driver = $this->find($id);
        $this->authorize('view', $driver);

        return DriverValueResource::collection($this->driverValues->list($this->tenantId(), (string) $driver->id, $request->query('period_key') === null ? null : (string) $request->query('period_key'), $this->perPage()));
    }

    public function storeValues(DriverValuesRequest $request, string $id): AnonymousResourceCollection
    {
        $driver = $this->find($id);
        $this->authorize('update', $driver);
        $data = $request->validated();

        return DriverValueResource::collection(collect($this->driverValues->upsert($this->tenantId(), (string) $driver->id, $data['period_key'], $data['values'], $this->userId())));
    }

    private function find(string $id): AllocationDriver
    {
        return AllocationDriver::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
