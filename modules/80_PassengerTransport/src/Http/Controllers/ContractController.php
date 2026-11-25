<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\PassengerTransport\Application\Commands\B2BConciergeBookingCommand;
use Modules\PassengerTransport\Application\Commands\B2BConciergeBookingHandler;
use Modules\PassengerTransport\Exceptions\PassengerTransportException;
use Modules\PassengerTransport\Http\Controllers\Concerns\InteractsWithPassengerTransport;
use Modules\PassengerTransport\Http\Requests\ConciergeBookingRequest;
use Modules\PassengerTransport\Http\Requests\ListFiltersRequest;
use Modules\PassengerTransport\Http\Requests\SaveHotelContractRequest;
use Modules\PassengerTransport\Http\Requests\SaveSchoolContractRequest;
use Modules\PassengerTransport\Http\Resources\BookingResource;
use Modules\PassengerTransport\Http\Resources\HotelContractResource;
use Modules\PassengerTransport\Http\Resources\SchoolContractResource;
use Modules\PassengerTransport\Models\HotelConciergeContract;
use Modules\PassengerTransport\Models\PassengerBooking;
use Modules\PassengerTransport\Models\SchoolBusContract;
use OpenApi\Attributes as OA;

/**
 * Hotel and agency concierge contracts (rate cards and commission), the concierge booking portal and the municipal school bus contracts.
 */
final class ContractController extends Controller
{
    use ApiResponse;
    use InteractsWithPassengerTransport;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/passenger-transport/hotel-contracts', operationId: 'passengerTransportListHotelContracts', summary: 'List hotel, agency and corporate concierge contracts', security: self::SECURITY, tags: ['Passenger Transport'], responses: [new OA\Response(response: 200, description: 'Contracts, paginated')])]
    public function hotels(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', HotelConciergeContract::class);

        $page = HotelConciergeContract::query()
            ->with('contact:id,company_name')
            ->when($request->input('q'), function ($rows, $term): void {
                $like = '%'.mb_strtolower((string) $term).'%';
                $rows->where(fn ($inner) => $inner->whereRaw('LOWER(code) LIKE ?', [$like])->orWhereRaw('LOWER(name) LIKE ?', [$like]));
            })
            ->orderBy('code')
            ->paginate($this->perPage(50));

        return $this->paged($page, HotelContractResource::class);
    }

    #[OA\Post(path: '/api/v1/passenger-transport/hotel-contracts', operationId: 'passengerTransportCreateHotelContract', summary: 'Create a concierge contract with its rate card', security: self::SECURITY, tags: ['Passenger Transport'], responses: [new OA\Response(response: 201, description: 'Contract created')])]
    public function storeHotel(SaveHotelContractRequest $request): JsonResponse
    {
        Gate::authorize('create', HotelConciergeContract::class);
        $contract = HotelConciergeContract::query()->create([...$request->validated(), 'tenant_id' => $this->tenantId(), 'created_by' => $this->userId()]);

        return $this->successResponse(new HotelContractResource($contract->load('contact:id,company_name')), $this->message('saved'), 201);
    }

    #[OA\Put(path: '/api/v1/passenger-transport/hotel-contracts/{id}', operationId: 'passengerTransportUpdateHotelContract', summary: 'Update a concierge contract', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Contract updated')])]
    public function updateHotel(SaveHotelContractRequest $request, string $id): JsonResponse
    {
        $contract = HotelConciergeContract::query()->findOrFail($id);
        Gate::authorize('update', $contract);
        $contract->update([...$request->validated(), 'updated_by' => $this->userId()]);

        return $this->successResponse(new HotelContractResource($contract->load('contact:id,company_name')), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/passenger-transport/hotel-contracts/{id}', operationId: 'passengerTransportDeleteHotelContract', summary: 'Delete a concierge contract that has no bookings', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Contract deleted')])]
    public function destroyHotel(string $id): JsonResponse
    {
        $contract = HotelConciergeContract::query()->findOrFail($id);
        Gate::authorize('delete', $contract);

        if (PassengerBooking::query()->where('hotel_contract_id', $id)->exists()) {
            throw new PassengerTransportException('contract_has_bookings', 409);
        }

        $contract->update(['deleted_by' => $this->userId()]);
        $contract->delete();

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Post(path: '/api/v1/passenger-transport/hotel-contracts/{id}/bookings', operationId: 'passengerTransportConciergeBooking', summary: 'Book a transfer at the tariff of a concierge contract', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Booking created and confirmed')])]
    public function concierge(ConciergeBookingRequest $request, string $id, B2BConciergeBookingHandler $handler): JsonResponse
    {
        Gate::authorize('create', PassengerBooking::class);
        $booking = $handler->handle(new B2BConciergeBookingCommand($id, $request->validated(), $this->tenantId(), $this->userId()));

        return $this->successResponse(new BookingResource($booking), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/passenger-transport/school-contracts', operationId: 'passengerTransportListSchoolContracts', summary: 'List municipal school bus contracts', security: self::SECURITY, tags: ['Passenger Transport'], responses: [new OA\Response(response: 200, description: 'Contracts, paginated')])]
    public function schools(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SchoolBusContract::class);

        $page = SchoolBusContract::query()
            ->with('contact:id,company_name')
            ->when($request->input('q'), function ($rows, $term): void {
                $like = '%'.mb_strtolower((string) $term).'%';
                $rows->where(fn ($inner) => $inner->whereRaw('LOWER(code) LIKE ?', [$like])->orWhereRaw('LOWER(name) LIKE ?', [$like]));
            })
            ->orderBy('code')
            ->paginate($this->perPage(50));

        return $this->paged($page, SchoolContractResource::class);
    }

    #[OA\Post(path: '/api/v1/passenger-transport/school-contracts', operationId: 'passengerTransportCreateSchoolContract', summary: 'Create a municipal school bus contract', security: self::SECURITY, tags: ['Passenger Transport'], responses: [new OA\Response(response: 201, description: 'Contract created')])]
    public function storeSchool(SaveSchoolContractRequest $request): JsonResponse
    {
        Gate::authorize('create', SchoolBusContract::class);
        $contract = SchoolBusContract::query()->create([...$request->validated(), 'tenant_id' => $this->tenantId(), 'created_by' => $this->userId()]);

        return $this->successResponse(new SchoolContractResource($contract->load('contact:id,company_name')), $this->message('saved'), 201);
    }

    #[OA\Put(path: '/api/v1/passenger-transport/school-contracts/{id}', operationId: 'passengerTransportUpdateSchoolContract', summary: 'Update a school bus contract', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Contract updated')])]
    public function updateSchool(SaveSchoolContractRequest $request, string $id): JsonResponse
    {
        $contract = SchoolBusContract::query()->findOrFail($id);
        Gate::authorize('update', $contract);
        $contract->update([...$request->validated(), 'updated_by' => $this->userId()]);

        return $this->successResponse(new SchoolContractResource($contract->load('contact:id,company_name')), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/passenger-transport/school-contracts/{id}', operationId: 'passengerTransportDeleteSchoolContract', summary: 'Delete a school bus contract that has no bookings', security: self::SECURITY, tags: ['Passenger Transport'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Contract deleted')])]
    public function destroySchool(string $id): JsonResponse
    {
        $contract = SchoolBusContract::query()->findOrFail($id);
        Gate::authorize('delete', $contract);

        if (PassengerBooking::query()->where('school_contract_id', $id)->exists()) {
            throw new PassengerTransportException('contract_has_bookings', 409);
        }

        $contract->update(['deleted_by' => $this->userId()]);
        $contract->delete();

        return $this->successResponse(null, $this->message('deleted'));
    }
}
