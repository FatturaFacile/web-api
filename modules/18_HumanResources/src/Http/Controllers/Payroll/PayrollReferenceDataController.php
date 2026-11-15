<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\HumanResources\Contracts\Payroll\WithholdingTariffImporterInterface;
use Modules\HumanResources\Http\Requests\Payroll\ImportWithholdingTariffsRequest;
use Modules\HumanResources\Http\Requests\Payroll\StoreRateSetRequest;
use Modules\HumanResources\Http\Requests\Payroll\StoreWageTypeRequest;
use Modules\HumanResources\Http\Requests\Payroll\StoreWithholdingTariffRequest;
use Modules\HumanResources\Http\Requests\Payroll\UpdateWageTypeRequest;
use Modules\HumanResources\Http\Resources\Payroll\RateSetResource;
use Modules\HumanResources\Http\Resources\Payroll\WageTypeResource;
use Modules\HumanResources\Http\Resources\Payroll\WithholdingTariffResource;
use Modules\HumanResources\Models\Payroll\PayrollRateSet;
use Modules\HumanResources\Models\Payroll\WageType;
use Modules\HumanResources\Models\Payroll\WithholdingTariff;
use Symfony\Component\HttpFoundation\Response;

/**
 * Swiss payroll reference data: wage types, rate sets and withholding tax tariffs. Lists include the system
 * defaults (null tenant) next to the tenant's own records.
 */
final class PayrollReferenceDataController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly WithholdingTariffImporterInterface $tariffImporter,
    ) {}

    public function wageTypes(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WageType::class);

        $wageTypes = WageType::query()
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')->value()))
            ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        return $this->successResponse(WageTypeResource::collection($wageTypes), 'Wage types retrieved successfully');
    }

    public function storeWageType(StoreWageTypeRequest $request): JsonResponse
    {
        $wageType = WageType::create(array_merge($request->validated(), [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenantId(),
        ]));

        return $this->successResponse(new WageTypeResource($wageType->refresh()), 'Wage type created', Response::HTTP_CREATED);
    }

    public function updateWageType(UpdateWageTypeRequest $request, string $id): JsonResponse
    {
        $wageType = WageType::query()->findOrFail($id);
        $this->authorize('update', $wageType);

        $wageType->update($request->validated());

        return $this->successResponse(new WageTypeResource($wageType->refresh()), 'Wage type updated');
    }

    public function rateSets(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollRateSet::class);

        $rateSets = PayrollRateSet::query()
            ->when($request->filled('year'), fn ($query) => $query->where('year', $request->integer('year')))
            ->orderByDesc('valid_from')
            ->get();

        return $this->successResponse(RateSetResource::collection($rateSets), 'Rate sets retrieved successfully');
    }

    public function storeRateSet(StoreRateSetRequest $request): JsonResponse
    {
        $rateSet = PayrollRateSet::create(array_merge($request->validated(), [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenantId(),
        ]));

        return $this->successResponse(new RateSetResource($rateSet->refresh()), 'Rate set created', Response::HTTP_CREATED);
    }

    public function withholdingTariffs(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WithholdingTariff::class);

        $tariffs = WithholdingTariff::query()
            ->with('brackets')
            ->when($request->filled('canton'), fn ($query) => $query->where('canton', strtoupper($request->string('canton')->value())))
            ->when($request->filled('tariff_code'), fn ($query) => $query->where('tariff_code', strtoupper($request->string('tariff_code')->value())))
            ->when($request->filled('valid_on'), function ($query) use ($request): void {
                $day = $request->string('valid_on')->value();
                $query->whereDate('valid_from', '<=', $day)
                    ->where(fn ($inner) => $inner->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day));
            })
            ->orderBy('canton')
            ->orderBy('tariff_code')
            ->orderByDesc('valid_from')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return $this->successResponse(
            WithholdingTariffResource::collection($tariffs->getCollection()),
            'Withholding tax tariffs retrieved successfully',
            Response::HTTP_OK,
            ['current_page' => $tariffs->currentPage(), 'per_page' => $tariffs->perPage(), 'total' => $tariffs->total()],
        );
    }

    public function storeWithholdingTariff(StoreWithholdingTariffRequest $request): JsonResponse
    {
        $rows = [];

        foreach ((array) $request->validated('brackets') as $bracket) {
            $rows[] = [
                'canton' => $request->string('canton')->value(),
                'tariff_code' => $request->string('tariff_code')->value(),
                'valid_from' => $request->string('valid_from')->value(),
                'valid_to' => $request->input('valid_to'),
                'income_from' => $bracket['income_from'] ?? null,
                'income_to' => $bracket['income_to'] ?? null,
                'rate_percent' => $bracket['rate_percent'] ?? null,
                'fixed_amount' => $bracket['fixed_amount'] ?? null,
            ];
        }

        $this->tariffImporter->importRows($rows, $this->tenantId(), 'manual');

        $tariff = WithholdingTariff::query()
            ->with('brackets')
            ->where('tenant_id', $this->tenantId())
            ->where('canton', strtoupper($request->string('canton')->value()))
            ->where('tariff_code', strtoupper($request->string('tariff_code')->value()))
            ->whereDate('valid_from', $request->string('valid_from')->value())
            ->firstOrFail();

        return $this->successResponse(new WithholdingTariffResource($tariff), 'Withholding tax tariff saved', Response::HTTP_CREATED);
    }

    public function importWithholdingTariffs(ImportWithholdingTariffsRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $contents = $file !== null && ! is_array($file) ? (string) $file->get() : '';

        $result = $this->tariffImporter->import($contents, $this->tenantId(), 'csv_import');

        return $this->successResponse($result, 'Withholding tax tariffs imported', Response::HTTP_CREATED);
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }
}
