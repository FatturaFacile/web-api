<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\DTOs\CreateBomDTO;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Http\Requests\CreateBomRequest;
use Modules\Manufacturing\Http\Requests\ReviseBomRequest;
use Modules\Manufacturing\Http\Resources\MfgBomResource;
use Modules\Manufacturing\Models\MfgBom;
use OpenApi\Attributes as OA;

/**
 * Bills of materials: draft revisions, edits of drafts, release (activation) with effective dates, revisions,
 * multi-level explosion.
 */
final class BomController extends Controller
{
    use InteractsWithManufacturing;

    public function __construct(private readonly BillOfMaterialsServiceInterface $boms) {}

    /**
     * Paginated BOM revisions.
     */
    #[OA\Get(path: '/api/v1/manufacturing/boms', operationId: 'listManufacturingBoms', description: 'Paginated BOM revisions of the active company, filtered by item and status.', summary: 'List BOM revisions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing BOMs'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgItemFilter'), new OA\Parameter(ref: '#/components/parameters/MfgStatus'), new OA\Parameter(ref: '#/components/parameters/MfgPage'), new OA\Parameter(ref: '#/components/parameters/MfgPerPage')],
        responses: [new OA\Response(response: 200, description: 'BOM revisions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgBom'))])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = MfgBom::query()->where('tenant_id', $this->tenantId());
        if ($request->filled('mfg_item_id')) {
            $query->where('mfg_item_id', (string) $request->query('mfg_item_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return MfgBomResource::collection($query->orderBy('bom_number')->orderByDesc('version')->paginate($this->perPage($request)));
    }

    /**
     * Creates a draft BOM revision.
     */
    #[OA\Post(path: '/api/v1/manufacturing/boms', operationId: 'createManufacturingBom', description: 'Creates a draft BOM revision with its components; it becomes effective only when activated. Recursive structures are refused.', summary: 'Create BOM revision', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing BOMs'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgCreateBomRequest')),
        responses: [new OA\Response(response: 201, description: 'Draft created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgBom')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function store(CreateBomRequest $request): JsonResponse
    {
        $data = $request->validated();
        $bom = $this->boms->createBom(new CreateBomDTO(
            tenantId: $this->tenantId(),
            mfgItemId: (string) $data['mfg_item_id'],
            name: (string) $data['name'],
            components: array_values($data['components']),
            revision: (string) ($data['revision'] ?? 'A'),
            bomType: (string) ($data['bom_type'] ?? 'standard'),
            baseQuantity: (string) ($data['base_quantity'] ?? '1'),
            uom: (string) ($data['uom'] ?? 'PCS'),
            effectiveFrom: $data['effective_from'] ?? null,
            effectiveTo: $data['effective_to'] ?? null,
            notes: $data['notes'] ?? null,
            bomNumber: $data['bom_number'] ?? null,
        ), $this->actor($request));

        return (new MfgBomResource($bom))->response()->setStatusCode(201);
    }

    /**
     * One BOM revision with its components.
     */
    #[OA\Get(path: '/api/v1/manufacturing/boms/{id}', operationId: 'getManufacturingBom', description: 'Returns a BOM revision of the active company with its components.', summary: 'Get BOM revision', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing BOMs'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'BOM revision', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgBom')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404)])]
    public function show(string $id): MfgBomResource
    {
        return new MfgBomResource($this->owned(MfgBom::class, $id)->load('components.componentItem'));
    }

    /**
     * Changes a draft revision.
     */
    #[OA\Put(path: '/api/v1/manufacturing/boms/{id}', operationId: 'updateManufacturingBom', description: 'Changes a draft revision; given components replace the existing ones. Released revisions are changed by a revision or an engineering change.', summary: 'Update draft BOM revision', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing BOMs'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgUpdateBomRequest')),
        responses: [new OA\Response(response: 200, description: 'Draft updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgBom')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function update(CreateBomRequest $request, string $id): MfgBomResource
    {
        $bom = $this->owned(MfgBom::class, $id);
        $this->authorize('update', $bom);

        return new MfgBomResource($this->boms->updateDraft($bom, $request->validated(), $this->actor($request)));
    }

    /**
     * Releases a draft revision.
     */
    #[OA\Post(path: '/api/v1/manufacturing/boms/{id}/activate', operationId: 'activateManufacturingBom', description: 'Releases a draft revision (manufacturing managers only): effective from its effective date (default today); the revision effective before ends the day before and becomes superseded.', summary: 'Activate BOM revision', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing BOMs'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Revision active', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgBom')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function activate(Request $request, string $id): MfgBomResource
    {
        $bom = $this->owned(MfgBom::class, $id);
        $this->authorize('activate', $bom);

        return new MfgBomResource($this->boms->activate($bom, $this->actor($request)));
    }

    /**
     * Multi-level explosion of a revision.
     */
    #[OA\Get(path: '/api/v1/manufacturing/boms/{id}/explode', operationId: 'explodeManufacturingBom', description: 'Explodes the revision over all levels for a quantity (scrap and yield included), sub-levels using the revisions effective on the date.', summary: 'Explode BOM', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing BOMs'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId'), new OA\Parameter(name: 'quantity', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'decimal', default: '1')), new OA\Parameter(name: 'date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [new OA\Response(response: 200, description: 'Explosion', content: new OA\JsonContent(properties: [new OA\Property(property: 'bom_id', type: 'string', format: 'uuid'), new OA\Property(property: 'quantity', type: 'string', format: 'decimal'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgBomExplosionRow'))])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function explode(Request $request, string $id): JsonResponse
    {
        $bom = $this->owned(MfgBom::class, $id);
        $input = $request->validate(['quantity' => ['nullable', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999999'], 'date' => ['nullable', 'date']]);
        $quantity = (string) ($input['quantity'] ?? '1');

        return response()->json(['bom_id' => $bom->id, 'quantity' => $quantity, 'data' => $this->boms->explodeBom($bom, $quantity, $input['date'] ?? null)]);
    }

    /**
     * Copies a revision into a new draft revision.
     */
    #[OA\Post(path: '/api/v1/manufacturing/boms/{id}/revise', operationId: 'reviseManufacturingBom', description: 'Copies the revision into a new draft revision of the same BOM number (next letter or number when not given), applying the component changes.', summary: 'Revise BOM', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing BOMs'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgReviseBomRequest')),
        responses: [new OA\Response(response: 201, description: 'Draft revision created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgBom')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function revise(ReviseBomRequest $request, string $id): JsonResponse
    {
        $bom = $this->owned(MfgBom::class, $id);
        $this->authorize('update', $bom);
        $data = $request->validated();
        $draft = $this->boms->reviseBom($bom, $data['new_revision'] ?? null, (string) $data['reason'], array_values($data['components'] ?? []), $data['effective_from'] ?? null, $this->actor($request));

        return (new MfgBomResource($draft))->response()->setStatusCode(201);
    }
}
