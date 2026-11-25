<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\PassengerTransport\Domain\Enums\BookingStatus;
use Modules\PassengerTransport\Exceptions\PassengerTransportException;
use Modules\PassengerTransport\Http\Controllers\Concerns\InteractsWithPassengerTransport;
use Modules\PassengerTransport\Http\Requests\ListFiltersRequest;
use Modules\PassengerTransport\Http\Requests\SaveDriverComplianceRequest;
use Modules\PassengerTransport\Http\Requests\SaveVehicleRequest;
use Modules\PassengerTransport\Http\Resources\DriverComplianceResource;
use Modules\PassengerTransport\Http\Resources\VehicleResource;
use Modules\PassengerTransport\Models\DriverComplianceState;
use Modules\PassengerTransport\Models\FleetVehicle;
use Modules\PassengerTransport\Models\PassengerBooking;
use OpenApi\Attributes as OA;

/**
 * Vehicles of the passenger fleet and the compliance records (licences, certificates, counters) of the drivers.
 */
final class FleetController extends Controller
{
    use ApiResponse;
    use InteractsWithPassengerTransport;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/passenger-transport/vehicles', operationId: 'passengerTransportListVehicles', summary: 'List the vehicles of the passenger fleet', security: self::SECURITY, tags: ['Passenger Transport'], responses: [new OA\Response(response: 200, description: 'Vehicles, paginated')])]
    public function vehicles(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', FleetVehicle::class);

        $page = FleetVehicle::query()
            ->when($request->input('q'), fn ($rows, $term) => $rows->whereRaw('LOWER(license_plate) LIKE ?', ['%'.mb_strtolower((string) $term).'%']))
            ->orderBy('license_plate')
            ->paginate($this->perPage(50));

        return $this->paged($page, VehicleResource::class);
    }

    #[OA\Post(path: '/api/v1/passenger-transport/vehicles', operationId: 'passengerTransportCreateVehicle', summary: 'Add a vehicle to the passenger fleet', security: self::SECURITY, tags: ['Passenger Transport'], responses: [new OA\Response(response: 201, description: 'Vehicle created')])]
    public function storeVehicle(SaveVehicleRequest $request): JsonResponse
    {
        Gate::authorize('create', FleetVehicle::class);
        $vehicle = FleetVehicle::query()->create([...$request->validated(), 'tenant_id' => $this->tenantId(), 'created_by' => $this->userId()]);

        return $this->successResponse(new VehicleResource($vehicle->refresh()), $this->message('saved'), 201);
    }

    #[OA\Put(path: '/api/v1/passenger-transport/vehicles/{id}', operationId: 'passengerTransportUpdateVehicle', summary: 'Update a vehicle', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Vehicle updated')])]
    public function updateVehicle(SaveVehicleRequest $request, string $id): JsonResponse
    {
        $vehicle = FleetVehicle::query()->findOrFail($id);
        Gate::authorize('update', $vehicle);
        $vehicle->update([...$request->validated(), 'updated_by' => $this->userId()]);

        return $this->successResponse(new VehicleResource($vehicle->refresh()), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/passenger-transport/vehicles/{id}', operationId: 'passengerTransportDeleteVehicle', summary: 'Delete a vehicle that has no open trips', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Vehicle deleted')])]
    public function destroyVehicle(string $id): JsonResponse
    {
        $vehicle = FleetVehicle::query()->findOrFail($id);
        Gate::authorize('delete', $vehicle);

        if (PassengerBooking::query()->where('assigned_vehicle_id', $id)->whereIn('status', [BookingStatus::Assigned->value, BookingStatus::EnRouteToPickup->value, BookingStatus::AtPickupWaiting->value, BookingStatus::PassengerOnBoard->value])->exists()) {
            throw new PassengerTransportException('vehicle_has_open_trips', 409);
        }

        $vehicle->update(['deleted_by' => $this->userId()]);
        $vehicle->delete();

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Get(path: '/api/v1/passenger-transport/drivers', operationId: 'passengerTransportListDrivers', summary: 'List the compliance records of the drivers', security: self::SECURITY, tags: ['Passenger Transport'], responses: [new OA\Response(response: 200, description: 'Drivers, paginated')])]
    public function drivers(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', DriverComplianceState::class);

        $page = DriverComplianceState::query()
            ->with('employee:id,full_name')
            ->when($request->input('q'), fn ($rows, $term) => $rows->whereHas('employee', fn ($employee) => $employee->whereRaw('LOWER(full_name) LIKE ?', ['%'.mb_strtolower((string) $term).'%'])))
            ->orderBy('created_at')
            ->paginate($this->perPage(50));

        return $this->paged($page, DriverComplianceResource::class);
    }

    #[OA\Post(path: '/api/v1/passenger-transport/drivers', operationId: 'passengerTransportCreateDriver', summary: 'Set up the compliance record of a driver', security: self::SECURITY, tags: ['Passenger Transport'], responses: [new OA\Response(response: 201, description: 'Driver record created')])]
    public function storeDriver(SaveDriverComplianceRequest $request): JsonResponse
    {
        Gate::authorize('create', DriverComplianceState::class);

        if (DriverComplianceState::query()->where('driver_employee_id', $request->validated('driver_employee_id'))->exists()) {
            throw new PassengerTransportException('driver_record_exists', 409);
        }

        $state = DriverComplianceState::query()->create([...$request->validated(), 'tenant_id' => $this->tenantId(), 'created_by' => $this->userId()]);

        return $this->successResponse(new DriverComplianceResource($state->load('employee:id,full_name')), $this->message('saved'), 201);
    }

    #[OA\Put(path: '/api/v1/passenger-transport/drivers/{id}', operationId: 'passengerTransportUpdateDriver', summary: 'Update the licences, certificates and counters of a driver', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Driver record updated')])]
    public function updateDriver(SaveDriverComplianceRequest $request, string $id): JsonResponse
    {
        $state = DriverComplianceState::query()->findOrFail($id);
        Gate::authorize('update', $state);
        $state->update([...$request->validated(), 'updated_by' => $this->userId(), 'counters_updated_at' => now()]);

        return $this->successResponse(new DriverComplianceResource($state->load('employee:id,full_name')), $this->message('saved'));
    }
}
