<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Manufacturing\Contracts\BomExplosionServiceInterface;
use Modules\Manufacturing\Contracts\CoProductAllocationServiceInterface;
use Modules\Manufacturing\Contracts\PreferentialOriginServiceInterface;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Http\Requests\BomAnalysisRequest;
use Modules\Manufacturing\Http\Requests\CreateOrReviseBomWithEcoRequest;
use Modules\Manufacturing\Http\Requests\ImportBomRequest;
use Modules\Manufacturing\Http\Requests\MassReplaceComponentRequest;
use Modules\Manufacturing\Http\Requests\ReplaceCoProductsRequest;
use Modules\Manufacturing\Http\Resources\MfgBomResource;
use Modules\Manufacturing\Http\Resources\MfgEngineeringChangeOrderResource;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Services\Bom\BomDiffService;
use Modules\Manufacturing\Services\Bom\BomImportService;
use Modules\Manufacturing\Services\Bom\BomMassReplaceService;
use Modules\Manufacturing\Services\Bom\BomRevisionService;
use Modules\Manufacturing\Services\Bom\BomWhereUsedService;
use OpenApi\Attributes as OA;

/**
 * Multi-level BOM management: revisions through engineering changes with their structural diff, explosion with waste,
 * phantom flattening and lead times, where-used implosion, mass replacement of a component, co/by-products, the EUR.1
 * origin test and the import dry run.
 */
final class BomEngineeringController extends Controller
{
    use InteractsWithManufacturing;

    public function __construct(
        private readonly BomRevisionService $revisions,
        private readonly BomExplosionServiceInterface $explosion,
        private readonly BomWhereUsedService $whereUsed,
        private readonly BomMassReplaceService $massReplace,
        private readonly BomDiffService $diffs,
        private readonly BomImportService $imports,
        private readonly CoProductAllocationServiceInterface $coProducts,
        private readonly PreferentialOriginServiceInterface $origin,
    ) {}

    /**
     * Creates a BOM draft, or revises a released BOM through an engineering change.
     */
    #[OA\Post(path: '/api/v1/manufacturing/boms/revisions', operationId: 'createOrReviseManufacturingBom', description: 'Without a source BOM creates a draft (circular structures refused). With a source BOM clones it into a proposed revision tied to a new engineering change that stores the structural diff and is submitted for approval.', summary: 'Create or revise BOM with engineering change', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing BOMs'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [new OA\Response(response: 201, description: 'Draft or proposed revision with its change', content: new OA\JsonContent(type: 'object')), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function createOrRevise(CreateOrReviseBomWithEcoRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['source_bom_id'])) {
            $source = $this->owned(MfgBom::class, (string) $data['source_bom_id']);
            $this->authorize('update', $source);
            $result = $this->revisions->revise($source, (string) $data['reason'], $data['title'] ?? null, $data['new_revision'] ?? null, $data['effective_from'] ?? null, array_values($data['components'] ?? []), $this->actor($request));

            return response()->json([
                'data' => (new MfgBomResource($result['bom']))->resolve($request),
                'engineering_change' => (new MfgEngineeringChangeOrderResource($result['eco']))->resolve($request),
            ], 201);
        }

        $bom = $this->revisions->create($this->tenantId(), (string) $data['mfg_item_id'], (string) $data['name'], array_values($data['components']), $data, $this->actor($request));

        return response()->json(['data' => (new MfgBomResource($bom))->resolve($request), 'engineering_change' => null], 201);
    }

    /**
     * Explosion of a revision: single or multi-level, with waste, phantom flattening and lead times.
     */
    #[OA\Get(path: '/api/v1/manufacturing/boms/{id}/explosion', operationId: 'explodeManufacturingBomMultiLevel', description: 'Single or multi-level explosion for a quantity and date: proportional scrap and yield compound across levels, fixed setup waste is added once per line, phantom assemblies are flattened into their components and each line has its lead-time offset.', summary: 'Explode BOM (multi-level)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing BOMs'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Explosion', content: new OA\JsonContent(type: 'object')), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function explosion(BomAnalysisRequest $request, string $id): JsonResponse
    {
        $bom = $this->owned(MfgBom::class, $id);
        $input = $request->validated();

        return response()->json($this->explosion->explode($bom, (string) ($input['quantity'] ?? '1'), $input['date'] ?? null, (bool) ($input['multi_level'] ?? true), (bool) ($input['flatten_phantoms'] ?? true)));
    }

    /**
     * Where-used implosion of an item.
     */
    #[OA\Get(path: '/api/v1/manufacturing/items/{id}/implosion', operationId: 'implodeManufacturingItem', description: 'Bottom-up implosion: every sub-assembly and finished good that consumes the item at any depth with the cumulative coefficient, and the top-level products impacted.', summary: 'Where-used implosion', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing BOMs'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Implosion', content: new OA\JsonContent(type: 'object')), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404)])]
    public function implosion(BomAnalysisRequest $request, string $id): JsonResponse
    {
        $item = $this->owned(MfgItem::class, $id);

        return response()->json($this->whereUsed->implode($item, $request->validated()['date'] ?? null));
    }

    /**
     * Replaces a component across the released BOMs that use it.
     */
    #[OA\Post(path: '/api/v1/manufacturing/boms/mass-replace', operationId: 'massReplaceManufacturingBomComponent', description: 'Replaces an obsolete component by a substitute in every released BOM that uses it (manufacturing managers only): one proposed revision and engineering change per BOM. With dry_run only reports the affected BOMs.', summary: 'Mass replace component', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing BOMs'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [new OA\Response(response: 200, description: 'Replacement report', content: new OA\JsonContent(type: 'object')), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function massReplace(MassReplaceComponentRequest $request): JsonResponse
    {
        $data = $request->validated();
        foreach ($this->massReplace->affected($this->tenantId(), (string) $data['obsolete_item_id']) as $bom) {
            Gate::authorize('activate', $bom);
        }

        return response()->json($this->massReplace->replace(
            $this->tenantId(),
            (string) $data['obsolete_item_id'],
            (string) $data['substitute_item_id'],
            (string) $data['reason'],
            $data['effective_from'] ?? null,
            (bool) ($data['dry_run'] ?? false),
            $this->actor($request)
        ));
    }

    /**
     * Structural diff between two revisions.
     */
    #[OA\Get(path: '/api/v1/manufacturing/boms/{id}/diff/{otherId}', operationId: 'diffManufacturingBoms', description: 'Components added, removed and modified (quantity, scrap, yield, waste) going from the first revision to the second.', summary: 'Diff two BOM revisions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing BOMs'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId'), new OA\Parameter(name: 'otherId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Diff', content: new OA\JsonContent(type: 'object')), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404)])]
    public function diff(string $id, string $otherId): JsonResponse
    {
        return response()->json($this->diffs->between($this->owned(MfgBom::class, $id), $this->owned(MfgBom::class, $otherId)));
    }

    /**
     * Replaces the co/by-products of a draft revision.
     */
    #[OA\Put(path: '/api/v1/manufacturing/boms/{id}/co-products', operationId: 'replaceManufacturingBomCoProducts', description: 'Replaces the co/by-products of a draft revision with their cost shares; the shares may not exceed 100% (the primary output carries the remainder).', summary: 'Set BOM co/by-products', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing BOMs'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [new OA\Response(response: 200, description: 'Revision with co-products and allocation', content: new OA\JsonContent(type: 'object')), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function replaceCoProducts(ReplaceCoProductsRequest $request, string $id): JsonResponse
    {
        $bom = $this->owned(MfgBom::class, $id);
        $this->authorize('update', $bom);
        $updated = $this->coProducts->replace($bom, array_values($request->validated()['co_products']));

        return response()->json(['data' => (new MfgBomResource($updated))->resolve($request), 'allocation' => $this->coProducts->allocation($updated)]);
    }

    /**
     * Cost allocation between the primary output and the co/by-products.
     */
    #[OA\Get(path: '/api/v1/manufacturing/boms/{id}/cost-allocation', operationId: 'getManufacturingBomCostAllocation', description: 'The cost shares of the primary output and every co/by-product, totalling 100%.', summary: 'BOM cost allocation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing BOMs'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Allocation', content: new OA\JsonContent(type: 'object')), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404)])]
    public function costAllocation(string $id): JsonResponse
    {
        return response()->json($this->coProducts->allocation($this->owned(MfgBom::class, $id)));
    }

    /**
     * Preferential origin (EUR.1) test of a revision.
     */
    #[OA\Get(path: '/api/v1/manufacturing/boms/{id}/preferential-origin', operationId: 'getManufacturingBomPreferentialOrigin', description: 'Value of the non-originating materials of the whole multi-level BOM against the ex-works price: eligible for EUR.1 when the share does not exceed the threshold (50% by default).', summary: 'EUR.1 origin test', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing BOMs'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Origin test', content: new OA\JsonContent(type: 'object')), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function preferentialOrigin(BomAnalysisRequest $request, string $id): JsonResponse
    {
        $input = $request->validated();

        return response()->json($this->origin->evaluate($this->owned(MfgBom::class, $id), $input['date'] ?? null, isset($input['threshold_percentage']) ? (string) $input['threshold_percentage'] : null));
    }

    /**
     * Dry run or commit of a BOM import.
     */
    #[OA\Post(path: '/api/v1/manufacturing/boms/import', operationId: 'importManufacturingBom', description: 'Validates rows mapped from a CAD, CSV or Excel file (unknown items, unit mismatches, duplicates, circular structures). A dry run only reports; a real run creates the BOM draft when every row is valid.', summary: 'Import BOM', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing BOMs'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [new OA\Response(response: 200, description: 'Dry-run report', content: new OA\JsonContent(type: 'object')), new OA\Response(response: 201, description: 'Draft created', content: new OA\JsonContent(type: 'object')), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function import(ImportBomRequest $request): JsonResponse
    {
        $data = $request->validated();
        $report = $this->imports->dryRun($this->tenantId(), (string) $data['product_code'], array_values($data['rows']));

        if ((bool) $data['dry_run']) {
            return response()->json($report);
        }
        if (! $report['valid'] || $report['product_item_id'] === null) {
            return response()->json(['message' => 'The import has invalid rows.', 'errors' => ['rows' => ['The import has invalid rows.']], 'report' => $report], 422);
        }

        $bom = $this->revisions->create($this->tenantId(), $report['product_item_id'], (string) $data['name'], $report['components'], [], $this->actor($request));

        return response()->json(['data' => (new MfgBomResource($bom))->resolve($request), 'report' => $report], 201);
    }
}
