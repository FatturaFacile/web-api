<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Contracts\Http\Controllers\Concerns\InteractsWithContractAuthoring;
use Modules\Contracts\Http\Requests\SaveTemplateRequest;
use Modules\Contracts\Http\Requests\StoreVersionFromTemplateRequest;
use Modules\Contracts\Http\Resources\ContractTemplateResource;
use Modules\Contracts\Models\ContractTemplate;
use Modules\Contracts\Services\ContractTemplateService;
use Modules\Contracts\Services\TemplateAssembler;
use OpenApi\Attributes as OA;

/**
 * Contract templates: ordered clauses of the library with declared variables.
 */
final class ContractTemplateController extends Controller
{
    use InteractsWithContractAuthoring;

    public function __construct(private readonly ContractTemplateService $templates, private readonly TemplateAssembler $assembler) {}

    #[OA\Get(path: '/api/v1/contract-authoring/templates', operationId: 'listContractTemplates', description: 'Contract templates by name. Filters: status, contract_kind.', summary: 'List templates', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Contract Authoring'], responses: [
        new OA\Response(response: 200, description: 'Templates', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ContractTemplateResource'))])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ContractTemplate::class);
        $filters = $request->validate(['status' => ['nullable', 'in:'.implode(',', ContractTemplate::Statuses)], 'contract_kind' => ['nullable', 'in:customer,supplier']]);

        return ContractTemplateResource::collection(
            ContractTemplate::query()
                ->with('templateClauses.clause')
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['contract_kind']), static fn ($query) => $query->where('contract_kind', $filters['contract_kind']))
                ->orderBy('name')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/contract-authoring/templates', operationId: 'storeContractTemplate', description: 'Create a template with its variables and ordered clauses. An active template only holds approved clauses.', summary: 'Create a template', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractTemplateRequest')), tags: ['Contract Authoring'], responses: [
        new OA\Response(response: 201, description: 'Template created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractTemplateResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
    ])]
    public function store(SaveTemplateRequest $request): JsonResponse
    {
        $this->authorize('create', ContractTemplate::class);

        return (new ContractTemplateResource($this->templates->save(null, $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/contract-authoring/templates/{id}', operationId: 'showContractTemplate', description: 'The template with its ordered clauses.', summary: 'Show a template', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Template', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractTemplateResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
    ])]
    public function show(string $id): ContractTemplateResource
    {
        $template = ContractTemplate::query()->with('templateClauses.clause')->findOrFail($id);
        $this->authorize('view', $template);

        return new ContractTemplateResource($template);
    }

    #[OA\Put(path: '/api/v1/contract-authoring/templates/{id}', operationId: 'updateContractTemplate', description: 'Change the template; sending clauses replaces the ordered list.', summary: 'Update a template', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractTemplateRequest')), tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Template updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractTemplateResource')])),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
    ])]
    public function update(SaveTemplateRequest $request, string $id): ContractTemplateResource
    {
        $template = ContractTemplate::query()->findOrFail($id);
        $this->authorize('update', $template);

        return new ContractTemplateResource($this->templates->save($template, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/contract-authoring/templates/{id}', operationId: 'deleteContractTemplate', description: 'Soft delete; contract texts already assembled are not affected.', summary: 'Delete a template', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 204, description: 'Template deleted'),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $template = ContractTemplate::query()->findOrFail($id);
        $this->authorize('delete', $template);
        $template->delete();

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/contract-authoring/templates/{id}/preview', operationId: 'previewContractTemplate', description: 'Assemble the text with the given variables without storing anything.', summary: 'Preview an assembled text', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractTemplateAssembleRequest')), tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Assembled text'),
        new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
    ])]
    public function preview(StoreVersionFromTemplateRequest $request, string $id): JsonResponse
    {
        $template = ContractTemplate::query()->findOrFail($id);
        $this->authorize('view', $template);
        $data = $request->validated();

        return response()->json(['data' => ['body' => $this->assembler->assemble($template, $data['variables'] ?? [], $data['include_optional'] ?? [], $data['fallback_ranks'] ?? [])]]);
    }
}
