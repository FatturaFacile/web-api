<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\TravelExpense\Http\Controllers\Concerns\InteractsWithTravel;
use Modules\TravelExpense\Http\Requests\MileageRateRequest;
use Modules\TravelExpense\Http\Requests\PerDiemRateRequest;
use Modules\TravelExpense\Http\Requests\SeedRatesRequest;
use Modules\TravelExpense\Http\Resources\MileageRateResource;
use Modules\TravelExpense\Http\Resources\PerDiemRateResource;
use Modules\TravelExpense\Models\TravelMileageRate;
use Modules\TravelExpense\Models\TravelPerDiemRate;
use Modules\TravelExpense\Services\RateService;
use OpenApi\Attributes as OA;

/**
 * The per-diem and mileage rate tables of the company, per jurisdiction (IT, CH), country and year.
 */
final class RateController extends Controller
{
    use InteractsWithTravel;

    public function __construct(private readonly RateService $rates) {}

    #[OA\Get(path: '/api/v1/travel/rates/per-diem', operationId: 'listTravelPerDiemRates', description: 'The per-diem rate table of the company, latest year first. Filters: jurisdiction, year, scope.', summary: 'List per-diem rates', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Travel'], parameters: [
        new OA\Parameter(name: 'jurisdiction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['IT', 'CH'])),
        new OA\Parameter(name: 'year', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'scope', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['domestic', 'abroad'])),
    ], responses: [
        new OA\Response(response: 200, description: 'Per-diem rates', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TravelPerDiemRate'))])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
    ])]
    public function perDiemIndex(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TravelPerDiemRate::class);

        return PerDiemRateResource::collection(
            TravelPerDiemRate::query()
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('jurisdiction'), static fn ($query) => $query->where('jurisdiction', (string) $request->query('jurisdiction')))
                ->when($request->filled('year'), static fn ($query) => $query->where('year', (int) $request->query('year')))
                ->when($request->filled('scope'), static fn ($query) => $query->where('scope', (string) $request->query('scope')))
                ->orderByDesc('year')->orderBy('jurisdiction')->orderBy('scope')->orderBy('country')
                ->get()
        );
    }

    #[OA\Post(path: '/api/v1/travel/rates/per-diem', operationId: 'storeTravelPerDiemRate', description: 'Adds a row of the per-diem table. One row per jurisdiction, year, scope and country (the generic row has no country). Owners, administrators, HR, accountants and members granted travel.rates.', summary: 'Add a per-diem rate', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TravelPerDiemRateRequest')), tags: ['Travel'], responses: [
        new OA\Response(response: 201, description: 'Rate added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelPerDiemRate')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function perDiemStore(PerDiemRateRequest $request): JsonResponse
    {
        $this->authorize('create', TravelPerDiemRate::class);

        return (new PerDiemRateResource($this->rates->createPerDiemRate($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/travel/rates/per-diem/{id}', operationId: 'updateTravelPerDiemRate', description: 'Replaces a row of the per-diem table. Days already calculated keep the amounts they were calculated with.', summary: 'Update a per-diem rate', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TravelPerDiemRateRequest')), tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 200, description: 'Rate updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelPerDiemRate')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function perDiemUpdate(PerDiemRateRequest $request, string $id): PerDiemRateResource
    {
        $rate = TravelPerDiemRate::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $rate);

        return new PerDiemRateResource($this->rates->updatePerDiemRate($rate, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/travel/rates/per-diem/{id}', operationId: 'deleteTravelPerDiemRate', description: 'Removes a row of the per-diem table.', summary: 'Remove a per-diem rate', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 204, description: 'Rate removed'),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
    ])]
    public function perDiemDestroy(string $id): Response
    {
        $rate = TravelPerDiemRate::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('delete', $rate);
        $rate->delete();

        return response()->noContent();
    }

    #[OA\Get(path: '/api/v1/travel/rates/mileage', operationId: 'listTravelMileageRates', description: 'The mileage rate table of the company, latest year first. Filters: jurisdiction, year, vehicle_type.', summary: 'List mileage rates', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Travel'], parameters: [
        new OA\Parameter(name: 'jurisdiction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['IT', 'CH'])),
        new OA\Parameter(name: 'year', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'vehicle_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['car', 'motorcycle', 'bicycle'])),
    ], responses: [
        new OA\Response(response: 200, description: 'Mileage rates', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TravelMileageRate'))])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
    ])]
    public function mileageIndex(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TravelMileageRate::class);

        return MileageRateResource::collection(
            TravelMileageRate::query()
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('jurisdiction'), static fn ($query) => $query->where('jurisdiction', (string) $request->query('jurisdiction')))
                ->when($request->filled('year'), static fn ($query) => $query->where('year', (int) $request->query('year')))
                ->when($request->filled('vehicle_type'), static fn ($query) => $query->where('vehicle_type', (string) $request->query('vehicle_type')))
                ->orderByDesc('year')->orderBy('jurisdiction')->orderBy('vehicle_type')
                ->get()
        );
    }

    #[OA\Post(path: '/api/v1/travel/rates/mileage', operationId: 'storeTravelMileageRate', description: 'Adds a row of the mileage table. One row per jurisdiction, year and vehicle type.', summary: 'Add a mileage rate', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TravelMileageRateRequest')), tags: ['Travel'], responses: [
        new OA\Response(response: 201, description: 'Rate added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelMileageRate')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function mileageStore(MileageRateRequest $request): JsonResponse
    {
        $this->authorize('create', TravelMileageRate::class);

        return (new MileageRateResource($this->rates->createMileageRate($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/travel/rates/mileage/{id}', operationId: 'updateTravelMileageRate', description: 'Replaces a row of the mileage table. Claims already saved keep their rate.', summary: 'Update a mileage rate', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TravelMileageRateRequest')), tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 200, description: 'Rate updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelMileageRate')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function mileageUpdate(MileageRateRequest $request, string $id): MileageRateResource
    {
        $rate = TravelMileageRate::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $rate);

        return new MileageRateResource($this->rates->updateMileageRate($rate, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/travel/rates/mileage/{id}', operationId: 'deleteTravelMileageRate', description: 'Removes a row of the mileage table.', summary: 'Remove a mileage rate', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 204, description: 'Rate removed'),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
    ])]
    public function mileageDestroy(string $id): Response
    {
        $rate = TravelMileageRate::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('delete', $rate);
        $rate->delete();

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/travel/rates/defaults', operationId: 'seedTravelRates', description: 'Adds the reference per-diem and mileage rows of a jurisdiction and year that the company does not have yet (IT: art. 51 TUIR thresholds of EUR 46.48 domestic and EUR 77.47 abroad; CH: flat CHF 75 per day). Existing rows are never changed. Review the values against the current regulation and the policy of the company.', summary: 'Add the reference rates', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TravelSeedRatesRequest')), tags: ['Travel'], responses: [
        new OA\Response(response: 200, description: 'Rows created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'per_diem_created', type: 'integer'), new OA\Property(property: 'mileage_created', type: 'integer')])])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function seedDefaults(SeedRatesRequest $request): JsonResponse
    {
        $this->authorize('create', TravelPerDiemRate::class);

        return response()->json(['data' => $this->rates->seedDefaults($this->tenantId(), (string) $request->validated('jurisdiction'), (int) $request->validated('year'))]);
    }
}
