<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\GreenCare\Application\Commands\RecordPhytosanitaryTreatmentCommand;
use Modules\GreenCare\Application\Handlers\RecordPhytosanitaryTreatmentHandler;
use Modules\GreenCare\Application\Services\OperatorLicenseGuard;
use Modules\GreenCare\Application\Services\TreatmentRegisterExporter;
use Modules\GreenCare\Domain\Compliance\ComplianceStrategyResolver;
use Modules\GreenCare\Domain\Enums\DosageUnit;
use Modules\GreenCare\Http\Controllers\Concerns\InteractsWithGreenCare;
use Modules\GreenCare\Http\Requests\StoreTreatmentRequest;
use Modules\GreenCare\Http\Requests\TreatmentExportRequest;
use Modules\GreenCare\Http\Requests\ValidateLicenseRequest;
use Modules\GreenCare\Http\Resources\LicenseResource;
use Modules\GreenCare\Http\Resources\TreatmentResource;
use Modules\GreenCare\Models\CrewTeamMember;
use Modules\GreenCare\Models\GreenSpaceSite;
use Modules\GreenCare\Models\PhytosanitaryTreatmentLog;
use OpenApi\Attributes as OA;

/**
 * Phytosanitary diary (Quaderno di Campagna): immutable entries, the live check of the operator license and the inspection PDF.
 */
final class TreatmentController extends Controller
{
    use ApiResponse;
    use InteractsWithGreenCare;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    private const array WITH = ['site', 'operator', 'license'];

    #[OA\Get(path: '/api/v1/green-care/treatments', operationId: 'greenCareTreatmentsIndex', summary: 'Entries of the phytosanitary diary', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Treatments')])]
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', PhytosanitaryTreatmentLog::class);

        $page = PhytosanitaryTreatmentLog::query()
            ->with(self::WITH)
            ->when(request()->query('from') !== null, static fn ($query) => $query->whereDate('treatment_date', '>=', (string) request()->query('from')))
            ->when(request()->query('to') !== null, static fn ($query) => $query->whereDate('treatment_date', '<=', (string) request()->query('to')))
            ->when(request()->query('site_id') !== null, static fn ($query) => $query->where('site_id', (string) request()->query('site_id')))
            ->when(request()->query('operator_id') !== null, static fn ($query) => $query->where('operator_id', (string) request()->query('operator_id')))
            ->orderByDesc('treatment_date')
            ->orderByDesc('created_at')
            ->paginate($this->perPage());

        return $this->paged($page, TreatmentResource::class);
    }

    #[OA\Post(path: '/api/v1/green-care/treatments', operationId: 'greenCareTreatmentsStore', summary: 'Write a treatment in the diary (the operator needs a valid license on the date)', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 201, description: 'Treatment')])]
    public function store(StoreTreatmentRequest $request, RecordPhytosanitaryTreatmentHandler $handler): JsonResponse
    {
        Gate::authorize('create', PhytosanitaryTreatmentLog::class);
        $data = $request->validated();

        $log = $handler->handle(new RecordPhytosanitaryTreatmentCommand(
            siteId: $data['site_id'],
            routeStopId: $data['route_stop_id'] ?? null,
            treatmentDate: Carbon::parse($data['treatment_date']),
            targetPestOrWeed: $data['target_pest_or_weed'],
            treatedCropOrSurface: $data['treated_crop_or_surface'],
            treatedAreaSqm: (float) $data['treated_area_sqm'],
            commercialProductName: $data['commercial_product_name'],
            registrationNumber: $data['registration_number'],
            activeIngredient: $data['active_ingredient'],
            dosageUsed: (float) $data['dosage_used'],
            dosageUnit: DosageUnit::from($data['dosage_unit']),
            waterVolumeLiters: isset($data['water_volume_liters']) ? (float) $data['water_volume_liters'] : null,
            warehouseProductId: $data['warehouse_product_id'] ?? null,
            warehouseId: $data['warehouse_id'] ?? null,
            operatorId: $data['operator_id'],
            reEntryIntervalHours: (int) ($data['re_entry_interval_hours'] ?? 0),
            weatherConditions: $data['weather_conditions_at_application'] ?? null,
            notes: $data['notes'] ?? null,
        ));

        return $this->createdResponse(new TreatmentResource($log->load(self::WITH)), $this->message('treatment_recorded'));
    }

    #[OA\Post(path: '/api/v1/green-care/treatments/validate-license', operationId: 'greenCareTreatmentsValidateLicense', summary: 'Check the license of an operator on a treatment date (live validation of the form)', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Validity of the license')])]
    public function validateLicense(ValidateLicenseRequest $request, OperatorLicenseGuard $guard, ComplianceStrategyResolver $strategies): JsonResponse
    {
        Gate::authorize('viewAny', PhytosanitaryTreatmentLog::class);
        $data = $request->validated();
        $jurisdiction = $data['jurisdiction'] ?? (isset($data['site_id']) ? GreenSpaceSite::query()->findOrFail($data['site_id'])->jurisdiction : $strategies->normalize(null));
        $result = $guard->check($data['operator_id'], Carbon::parse($data['treatment_date']), $strategies->for($jurisdiction));

        if ($result['license'] !== null) {
            $result['license']->setRelation('operator', CrewTeamMember::query()->find($data['operator_id']));
        }

        return $this->successResponse([
            'valid' => $result['valid'],
            'license' => $result['license'] === null ? null : (new LicenseResource($result['license']))->resolve(),
            'reason' => $result['reason'],
            'jurisdiction' => $jurisdiction,
        ], $this->message('license_checked'));
    }

    #[OA\Get(path: '/api/v1/green-care/treatments/export-pdf', operationId: 'greenCareTreatmentsExportPdf', summary: 'Inspection PDF of the phytosanitary register (Quaderno di Campagna), by period, site or operator', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'PDF file')])]
    public function exportPdf(TreatmentExportRequest $request, TreatmentRegisterExporter $exporter): Response
    {
        Gate::authorize('viewAny', PhytosanitaryTreatmentLog::class);

        return response($exporter->pdf($request->validated()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="phytosanitary-register-'.now()->format('Ymd').'.pdf"',
        ]);
    }

    #[OA\Get(path: '/api/v1/green-care/treatments/{id}', operationId: 'greenCareTreatmentsShow', summary: 'One entry of the diary', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Treatment')])]
    public function show(string $id): JsonResponse
    {
        $log = PhytosanitaryTreatmentLog::query()->with(self::WITH)->findOrFail($id);
        Gate::authorize('view', $log);

        return $this->successResponse(new TreatmentResource($log), $this->message('loaded'));
    }
}
