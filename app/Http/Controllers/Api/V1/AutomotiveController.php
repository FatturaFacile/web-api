<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Automotive\CompleteInspectionRequest;
use App\Http\Requests\Automotive\TireHotelCheckInRequest;
use App\Http\Responses\ApiResponse;
use App\Models\TireHotelRecord;
use App\Models\Vehicle;
use App\Models\VehicleInspection;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class AutomotiveController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/automotive/vehicles',
        operationId: 'getAutomotiveVehicles',
        description: 'List paginated automotive workshop vehicles with license plates and customer details.',
        summary: 'List vehicles',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Automotive'],
        responses: [
            new OA\Response(response: 200, description: 'Vehicles retrieved successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function vehicles(): JsonResponse
    {
        $vehicles = Vehicle::latest()->gridPaginate(RequestedPerPage::resolve(25));

        return $this->successResponse(data: $vehicles, message: 'Vehicles retrieved');
    }

    #[OA\Post(
        path: '/api/v1/automotive/tire-hotel/check-in',
        operationId: 'tireHotelCheckIn',
        description: 'Register seasonal tire storage (deposito gomme) with tread depth measurements.',
        summary: 'Check tires into hotel',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Automotive'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['vehicle_id', 'season', 'brand', 'size', 'front_left_tread_mm', 'front_right_tread_mm', 'rear_left_tread_mm', 'rear_right_tread_mm', 'warehouse_shelf_location'],
                properties: [
                    new OA\Property(property: 'vehicle_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'season', type: 'string', enum: ['summer', 'winter', 'all_season']),
                    new OA\Property(property: 'brand', type: 'string'),
                    new OA\Property(property: 'model', type: 'string', nullable: true),
                    new OA\Property(property: 'size', type: 'string', example: '205/55 R16'),
                    new OA\Property(property: 'dot', type: 'string', nullable: true),
                    new OA\Property(property: 'front_left_tread_mm', type: 'number', format: 'float', example: 6.5),
                    new OA\Property(property: 'front_right_tread_mm', type: 'number', format: 'float', example: 6.5),
                    new OA\Property(property: 'rear_left_tread_mm', type: 'number', format: 'float', example: 6.0),
                    new OA\Property(property: 'rear_right_tread_mm', type: 'number', format: 'float', example: 6.0),
                    new OA\Property(property: 'warehouse_shelf_location', type: 'string', example: 'SHELF-A12'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tire set checked into hotel', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function tireHotelCheckIn(TireHotelCheckInRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $record = TireHotelRecord::create([
            ...$validated,
            'status' => 'stored',
        ]);

        return $this->successResponse(
            data: ['id' => $record->id, 'status' => 'stored'],
            message: 'Tire set checked into hotel',
            statusCode: Response::HTTP_CREATED
        );
    }

    #[OA\Post(
        path: '/api/v1/automotive/tire-hotel/{id}/check-out',
        operationId: 'tireHotelCheckOut',
        description: 'Mark a stored tire set as fitted back onto the vehicle.',
        summary: 'Check tires out of hotel',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Automotive'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Tire set checked out and fitted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Record not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function tireHotelCheckOut(Request $request, string $id): JsonResponse
    {
        $record = TireHotelRecord::findOrFail($id);
        $record->update([
            'status' => 'fitted',
        ]);

        return $this->successResponse(message: 'Tire set checked out and fitted');
    }

    #[OA\Get(
        path: '/api/v1/automotive/tire-hotel/seasonal-switch-plan',
        operationId: 'seasonalSwitchPlan',
        description: 'Paginated schedule of the stored tire sets due for the summer/winter seasonal switch, with the vehicle plate, make and model. Supports the grid contract (sorting, column filters, grouping and grid_values).',
        summary: 'Seasonal tire switch plan',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Automotive'],
        responses: [
            new OA\Response(response: 200, description: 'Seasonal switch plan generated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function seasonalSwitchPlan(): JsonResponse
    {
        $plan = TireHotelRecord::query()
            ->with('vehicle')
            ->where('status', 'stored')
            ->latest()
            ->gridPaginate(RequestedPerPage::resolve(25))
            ->through(static function (TireHotelRecord $record): TireHotelRecord {
                $record->setAttribute('license_plate', $record->vehicle?->license_plate);
                $record->setAttribute('make', $record->vehicle?->make);
                $record->setAttribute('vehicle_model', $record->vehicle?->model);

                return $record;
            });

        return $this->successResponse(data: $plan, message: 'Seasonal switch plan generated');
    }

    #[OA\Get(
        path: '/api/v1/automotive/inspections/due-list',
        operationId: 'getInspectionsDueList',
        description: 'List vehicles due for statutory technical inspections (Revisioni periodiche).',
        summary: 'Inspections due list',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Automotive'],
        responses: [
            new OA\Response(response: 200, description: 'Inspections due list retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function inspectionsDueList(): JsonResponse
    {
        $inspections = VehicleInspection::join('vehicles', 'vehicles.id', '=', 'vehicle_inspections.vehicle_id')
            ->where('vehicle_inspections.result', 'pending')
            ->select('vehicle_inspections.*', 'vehicles.license_plate', 'vehicles.make')
            ->get();

        return $this->successResponse(data: $inspections, message: 'Inspections due list');
    }

    #[OA\Post(
        path: '/api/v1/automotive/inspections/{id}/complete',
        operationId: 'completeVehicleInspection',
        description: 'Record completion and outcome of vehicle technical inspection.',
        summary: 'Complete inspection',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Automotive'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['result'],
                properties: [
                    new OA\Property(property: 'result', type: 'string', enum: ['passed', 'failed', 'conditional']),
                    new OA\Property(property: 'certificate_number', type: 'string', nullable: true),
                    new OA\Property(property: 'inspector_notes', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Inspection completed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Inspection not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function completeInspection(CompleteInspectionRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();

        $inspection = VehicleInspection::findOrFail($id);
        $inspection->update([
            'passed_date' => now()->toDateString(),
            'result' => $validated['result'],
            'certificate_number' => $validated['certificate_number'] ?? null,
            'inspector_notes' => $validated['inspector_notes'] ?? null,
        ]);

        return $this->successResponse(message: 'Inspection completed');
    }
}
