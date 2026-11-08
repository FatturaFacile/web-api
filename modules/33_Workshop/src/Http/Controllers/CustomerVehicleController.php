<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Workshop\Http\Requests\SaveCustomerVehicleRequest;
use Modules\Workshop\Http\Resources\WorkshopResource;
use OpenApi\Attributes as OA;

/**
 * Writes the customer vehicles (owner contact, plate, VIN, make and model) that workshop folders, recurring services
 * and the tire hotel refer to. The list stays on GET /api/v1/automotive/vehicles.
 */
final class CustomerVehicleController extends Controller
{
    /**
     * Register a customer vehicle of the active company. The plate is unique in the company.
     */
    #[OA\Post(path: '/api/v1/automotive/vehicles', operationId: 'storeAutomotiveVehicle', description: 'Register a customer vehicle of the active company. The plate is unique in the company.', summary: 'Register vehicle', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AutomotiveVehicleRequest')), tags: ['Automotive'], responses: [
        new OA\Response(response: 201, description: 'Vehicle registered', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function store(SaveCustomerVehicleRequest $request): JsonResponse
    {
        $this->authorize('create', Vehicle::class);

        $vehicle = Vehicle::create($request->validated());

        return (new WorkshopResource($vehicle))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Return a customer vehicle with its owner contact.
     */
    #[OA\Get(path: '/api/v1/automotive/vehicles/{id}', operationId: 'showAutomotiveVehicle', description: 'Return a customer vehicle with its owner contact.', summary: 'Show vehicle', security: [['bearerAuth' => []]], tags: ['Automotive'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Vehicle', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
    ])]
    public function show(string $id): WorkshopResource
    {
        return new WorkshopResource($this->find($id, 'view')->load('client'));
    }

    /**
     * Change a customer vehicle, for example its owner or mileage.
     */
    #[OA\Put(path: '/api/v1/automotive/vehicles/{id}', operationId: 'updateAutomotiveVehicle', description: 'Change a customer vehicle, for example its owner or mileage.', summary: 'Change vehicle', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AutomotiveVehicleRequest')), tags: ['Automotive'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Vehicle changed', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    #[OA\Patch(path: '/api/v1/automotive/vehicles/{id}', operationId: 'patchAutomotiveVehicle', description: 'Change some fields of a customer vehicle.', summary: 'Partially change vehicle', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AutomotiveVehicleRequest')), tags: ['Automotive'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Vehicle changed', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function update(SaveCustomerVehicleRequest $request, string $id): WorkshopResource
    {
        $vehicle = $this->find($id, 'update');
        $vehicle->update($request->validated());

        return new WorkshopResource($vehicle);
    }

    /**
     * Archive a customer vehicle (soft delete): folders, services and tire sets that refer to it keep their history.
     */
    #[OA\Delete(path: '/api/v1/automotive/vehicles/{id}', operationId: 'destroyAutomotiveVehicle', description: 'Archive a customer vehicle (soft delete): folders, services and tire sets that refer to it keep their history.', summary: 'Archive vehicle', security: [['bearerAuth' => []]], tags: ['Automotive'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 204, description: 'Vehicle archived'),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $this->find($id, 'delete')->delete();

        return response()->noContent();
    }

    private function find(string $id, string $ability): Vehicle
    {
        $vehicle = Vehicle::query()->findOrFail($id);
        $this->authorize($ability, $vehicle);

        return $vehicle;
    }
}
