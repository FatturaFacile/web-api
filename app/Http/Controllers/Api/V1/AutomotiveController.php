<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AutomotiveController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    public function vehicles(): JsonResponse
    {
        $vehicles = DB::table('vehicles')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->whereNull('deleted_at')
            ->latest()
            ->paginate(25);

        return $this->successResponse(data: $vehicles, message: 'Vehicles retrieved');
    }

    public function tireHotelCheckIn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => ['required', 'uuid'],
            'season' => ['required', 'in:summer,winter,all_season'],
            'brand' => ['required', 'string'],
            'model' => ['nullable', 'string'],
            'size' => ['required', 'string'],
            'dot' => ['nullable', 'string'],
            'front_left_tread_mm' => ['required', 'numeric', 'between:0,15'],
            'front_right_tread_mm' => ['required', 'numeric', 'between:0,15'],
            'rear_left_tread_mm' => ['required', 'numeric', 'between:0,15'],
            'rear_right_tread_mm' => ['required', 'numeric', 'between:0,15'],
            'warehouse_shelf_location' => ['required', 'string'],
        ]);

        $id = (string) Str::uuid();
        DB::table('tire_hotel_records')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantContext->getTenantId(),
            ...$validated,
            'status' => 'stored',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->successResponse(
            data: ['id' => $id, 'status' => 'stored'],
            message: 'Tire set checked into hotel',
            statusCode: Response::HTTP_CREATED
        );
    }

    public function tireHotelCheckOut(Request $request, string $id): JsonResponse
    {
        DB::table('tire_hotel_records')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->where('id', $id)
            ->update([
                'status' => 'fitted',
                'updated_at' => now(),
            ]);

        return $this->successResponse(message: 'Tire set checked out and fitted');
    }

    public function seasonalSwitchPlan(): JsonResponse
    {
        $plan = DB::table('tire_hotel_records')
            ->join('vehicles', 'vehicles.id', '=', 'tire_hotel_records.vehicle_id')
            ->where('tire_hotel_records.tenant_id', $this->tenantContext->getTenantId())
            ->where('tire_hotel_records.status', 'stored')
            ->select('tire_hotel_records.*', 'vehicles.license_plate', 'vehicles.make', 'vehicles.model as vehicle_model')
            ->get();

        return $this->successResponse(data: $plan, message: 'Seasonal switch plan generated');
    }

    public function inspectionsDueList(): JsonResponse
    {
        $inspections = DB::table('vehicle_inspections')
            ->join('vehicles', 'vehicles.id', '=', 'vehicle_inspections.vehicle_id')
            ->where('vehicle_inspections.tenant_id', $this->tenantContext->getTenantId())
            ->where('vehicle_inspections.result', 'pending')
            ->select('vehicle_inspections.*', 'vehicles.license_plate', 'vehicles.make')
            ->get();

        return $this->successResponse(data: $inspections, message: 'Inspections due list');
    }

    public function completeInspection(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'result' => ['required', 'in:passed,failed,conditional'],
            'certificate_number' => ['nullable', 'string'],
            'inspector_notes' => ['nullable', 'string'],
        ]);

        DB::table('vehicle_inspections')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->where('id', $id)
            ->update([
                'passed_date' => now()->toDateString(),
                'result' => $validated['result'],
                'certificate_number' => $validated['certificate_number'] ?? null,
                'inspector_notes' => $validated['inspector_notes'] ?? null,
                'updated_at' => now(),
            ]);

        return $this->successResponse(message: 'Inspection completed');
    }
}
