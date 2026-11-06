<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Controllers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use InvalidArgumentException;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Http\Requests\ImportWithholdingTaxTariffsRequest;
use Modules\Payroll\Http\Requests\SaveAnnualParametersRequest;
use Modules\Payroll\Http\Requests\SaveFamilyAllowanceRateRequest;
use Modules\Payroll\Http\Resources\PayrollRecordResource;
use Modules\Payroll\Models\PayrollAnnualParameter;
use Modules\Payroll\Models\PayrollFamilyAllowanceRate;
use Modules\Payroll\Models\PayrollWithholdingTaxTariff;
use Modules\Payroll\Services\WithholdingTaxTariffImporter;
use OpenApi\Attributes as OA;

/**
 * Annual parameters, withholding tax tariffs and family allowances. Rows without a tenant are generic and read-only;
 * a company adds its own rows, which win over the generic ones.
 */
final class PayrollLegalTablesController extends PayrollController
{
    public function __construct(TenantContext $tenantContext, private readonly WithholdingTaxTariffImporter $importer)
    {
        parent::__construct($tenantContext);
    }

    /**
     * List annual parameters.
     */
    #[OA\Get(
        path: '/api/v1/payroll/annual-parameters',
        operationId: 'listPayrollAnnualParameters',
        description: 'Return the legal parameters by country and year: the generic rows and those of the company.',
        summary: 'List annual parameters',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 200, description: 'Annual parameters', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ]
    )]
    public function annualParameters(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PayrollAnnualParameter::class);

        return PayrollRecordResource::collection(PayrollAnnualParameter::query()->orderBy('country')->orderByDesc('year')->get());
    }

    /**
     * Save the parameters of a year for the company.
     */
    #[OA\Put(
        path: '/api/v1/payroll/annual-parameters/{country}/{year}',
        operationId: 'savePayrollAnnualParameters',
        description: 'Create or change the parameters of a year for the company: they win over the generic row of that year. Values not sent keep the defaults of the engine.',
        summary: 'Save annual parameters',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollAnnualParametersRequest')),
        tags: ['Payroll'],
        parameters: [
            new OA\Parameter(name: 'country', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['CH', 'IT'])),
            new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Annual parameters saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function saveAnnualParameters(SaveAnnualParametersRequest $request, string $country, int $year): PayrollRecordResource
    {
        $this->authorize('create', PayrollAnnualParameter::class);

        $row = PayrollAnnualParameter::query()->whereNotNull('tenant_id')->firstOrNew(['country' => strtoupper($country), 'year' => $year]);
        $row->fill($request->validated())->save();

        return $this->savedRecord($row);
    }

    /**
     * Summarize the imported tariffs.
     */
    #[OA\Get(
        path: '/api/v1/payroll/withholding-tax-tariffs',
        operationId: 'listWithholdingTaxTariffSummary',
        description: 'Return the imported tariffs by canton and validity date, with the number of codes and rows.',
        summary: 'Summarize withholding tax tariffs',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 200, description: 'Tariff summary', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ]
    )]
    public function tariffSummary(): JsonResponse
    {
        $this->authorize('viewAny', PayrollWithholdingTaxTariff::class);

        $summary = PayrollWithholdingTaxTariff::query()
            ->selectRaw('canton, valid_from, tenant_id IS NULL AS is_generic, COUNT(DISTINCT tariff_code) AS codes, COUNT(*) AS row_count')
            ->groupByRaw('canton, valid_from, tenant_id IS NULL')
            ->orderBy('canton')
            ->orderByDesc('valid_from')
            ->toBase()
            ->get();

        return response()->json(['data' => $summary]);
    }

    /**
     * Import an ESTV tariff file for the company.
     */
    #[OA\Post(
        path: '/api/v1/payroll/withholding-tax-tariffs/import',
        operationId: 'importWithholdingTaxTariffs',
        description: 'Import an official ESTV tariff file (record 06), as an upload in file or as text in content. The rows of the same canton and date imported by the company are replaced; generic rows are not touched.',
        summary: 'Import withholding tax tariffs',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'content', type: 'string')])),
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 200, description: 'Number of rows imported', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function importTariffs(ImportWithholdingTaxTariffsRequest $request): JsonResponse
    {
        $this->authorize('create', PayrollWithholdingTaxTariff::class);

        try {
            $imported = $this->importer->import($request->tariffContent(), $this->tenant()->id);
        } catch (InvalidArgumentException $exception) {
            throw new PayrollException($exception->getMessage(), previous: $exception);
        }

        return response()->json(['data' => ['imported' => $imported]]);
    }

    /**
     * List family allowances.
     */
    #[OA\Get(
        path: '/api/v1/payroll/family-allowance-rates',
        operationId: 'listFamilyAllowanceRates',
        description: 'Return the family allowances by canton: the generic rows and those of the company.',
        summary: 'List family allowance rates',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 200, description: 'Family allowance rates', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ]
    )]
    public function familyAllowanceRates(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PayrollFamilyAllowanceRate::class);

        return PayrollRecordResource::collection(PayrollFamilyAllowanceRate::query()->orderBy('canton')->orderByDesc('valid_from')->paginate($this->perPage($request, 100, 200)));
    }

    /**
     * Add the family allowances of a canton for the company.
     */
    #[OA\Post(
        path: '/api/v1/payroll/family-allowance-rates',
        operationId: 'storeFamilyAllowanceRate',
        description: 'Add the amounts of a canton from a date for the company. They cannot be below the federal minimum.',
        summary: 'Create family allowance rate',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollFamilyAllowanceRateRequest')),
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 201, description: 'Rate created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function storeFamilyAllowanceRate(SaveFamilyAllowanceRateRequest $request): JsonResponse
    {
        $this->authorize('create', PayrollFamilyAllowanceRate::class);

        return (new PayrollRecordResource(PayrollFamilyAllowanceRate::query()->create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Change the family allowances of the company.
     */
    #[OA\Put(
        path: '/api/v1/payroll/family-allowance-rates/{id}',
        operationId: 'updateFamilyAllowanceRate',
        description: 'Change a rate of the company. Generic rates are read-only.',
        summary: 'Update family allowance rate',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollFamilyAllowanceRateRequest')),
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Rate saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function updateFamilyAllowanceRate(SaveFamilyAllowanceRateRequest $request, string $id): PayrollRecordResource
    {
        $rate = PayrollFamilyAllowanceRate::query()->findOrFail($id);
        $this->authorize('update', $rate);
        $rate->update($request->validated());

        return new PayrollRecordResource($rate->refresh());
    }

    /**
     * Delete the family allowances of the company.
     */
    #[OA\Delete(
        path: '/api/v1/payroll/family-allowance-rates/{id}',
        operationId: 'deleteFamilyAllowanceRate',
        description: 'Delete a rate of the company. Generic rates are read-only.',
        summary: 'Delete family allowance rate',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 204, description: 'Rate deleted'),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        ]
    )]
    public function destroyFamilyAllowanceRate(string $id): HttpResponse
    {
        $rate = PayrollFamilyAllowanceRate::query()->findOrFail($id);
        $this->authorize('delete', $rate);
        $rate->delete();

        return response()->noContent();
    }
}
