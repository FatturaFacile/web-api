<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Budgeting\Http\Controllers\Concerns\InteractsWithBudgeting;
use Modules\Budgeting\Http\Requests\ImportRequest;
use Modules\Budgeting\Http\Resources\ImportResource;
use Modules\Budgeting\Models\BudgetImport;
use Modules\Budgeting\Models\BudgetVersion;
use Modules\Budgeting\Services\BudgetPeriods;
use Modules\Budgeting\Services\ExecutivePackService;
use Modules\Budgeting\Services\ImportService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel in and out: the budget import (template, validation, import history) and the executive budget pack.
 */
final class ExchangeController extends Controller
{
    use InteractsWithBudgeting;

    public function __construct(private readonly ImportService $imports, private readonly ExecutivePackService $pack) {}

    #[OA\Get(path: '/api/v1/budgeting/versions/{id}/import-template', operationId: 'downloadBudgetImportTemplate', description: 'The Excel template of the budget import: one row per account, cost centre, project and period.', summary: 'Budget import template', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 200, description: 'The .xlsx template', content: new OA\MediaType(mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
    ])]
    public function template(string $id): BinaryFileResponse
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('view', $version);

        return response()->download($this->imports->template($version), 'budget-import-template.xlsx')->deleteFileAfterSend(true);
    }

    #[OA\Post(path: '/api/v1/budgeting/versions/{id}/import', operationId: 'importBudgetFile', description: 'Import the budget of a draft version from an xlsx or csv file. The file is validated row by row and imported all or nothing; validate_only checks without importing. Mode replace empties the version first, merge overwrites only the cells of the file.', summary: 'Import a budget file', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(type: 'object'))), responses: [
        new OA\Response(response: 200, description: 'Import outcome', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function import(ImportRequest $request, string $id): JsonResponse
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('update', $version);
        $validated = $request->validated();
        $result = $this->imports->import($version, $request->file('file'), (string) ($validated['mode'] ?? BudgetImport::ModeMerge), (bool) ($validated['validate_only'] ?? false), $this->userId());

        return response()->json(['data' => (new ImportResource($result['import']))->resolve($request)]);
    }

    #[OA\Get(path: '/api/v1/budgeting/versions/{id}/imports', operationId: 'listBudgetImports', description: 'The imports of a version with their row errors, newest first.', summary: 'Budget import history', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 200, description: 'Imports', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
    ])]
    public function imports(string $id): AnonymousResourceCollection
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('view', $version);

        return ImportResource::collection(BudgetImport::query()->where('version_id', $version->id)->orderByDesc('created_at')->paginate($this->perPage()));
    }

    #[OA\Get(path: '/api/v1/budgeting/versions/{id}/executive-pack', operationId: 'downloadBudgetExecutivePack', description: 'The executive budget pack as one Excel workbook: summary, breakdown, versions, rolling forecast, variance by account and cost centre, volume and price effects, spend rules, open commitments and overrides.', summary: 'Executive budget pack', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId'), new OA\Parameter(name: 'through_period', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 12))], responses: [
        new OA\Response(response: 200, description: 'The .xlsx workbook', content: new OA\MediaType(mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function executivePack(Request $request, string $id): BinaryFileResponse
    {
        $version = BudgetVersion::query()->findOrFail($id);
        $this->authorize('view', $version);
        $filters = $request->validate(['through_period' => ['nullable', 'integer', 'min:1', 'max:12']]);
        $path = $this->pack->build($version, (int) ($filters['through_period'] ?? BudgetPeriods::Count));

        return response()->download($path, 'budget-pack-'.$version->fiscal_year.'.xlsx')->deleteFileAfterSend(true);
    }
}
