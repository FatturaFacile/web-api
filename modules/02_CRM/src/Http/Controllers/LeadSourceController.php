<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Contracts\LeadSourceCredentialServiceInterface;
use Modules\CRM\Http\Requests\RotateLeadSourceSecretRequest;
use Modules\CRM\Http\Requests\StoreLeadSourceRequest;
use Modules\CRM\Http\Resources\LeadSourceResource;
use Modules\CRM\Models\LeadSource;
use OpenApi\Attributes as OA;

final class LeadSourceController extends Controller
{
    private const array FIELDS = ['code', 'name', 'type', 'is_active', 'allowed_origins', 'field_mapping', 'default_product_line'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LeadSourceCredentialServiceInterface $credentials,
    ) {}

    #[OA\Get(
        path: '/api/v1/crm/lead-sources',
        operationId: 'listCrmLeadSources',
        summary: 'List lead sources',
        tags: ['CRM'],
        responses: [new OA\Response(response: 200, description: 'Lead sources')]
    )]
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', LeadSource::class);

        $sources = LeadSource::query()->where('tenant_id', $this->tenantId())->orderBy('name')->paginate(50);

        return response()->json([
            'data' => LeadSourceResource::collection($sources->getCollection()),
            'meta' => [
                'current_page' => $sources->currentPage(),
                'total' => $sources->total(),
            ],
        ]);
    }

    /**
     * Creates the source with fresh credentials, returned once as webhook_secret and api_key.
     */
    #[OA\Post(
        path: '/api/v1/crm/lead-sources',
        operationId: 'createCrmLeadSource',
        summary: 'Create lead source',
        tags: ['CRM'],
        responses: [new OA\Response(response: 201, description: 'Lead source created')]
    )]
    public function store(StoreLeadSourceRequest $request): JsonResponse
    {
        $this->authorize('create', LeadSource::class);

        $data = $request->safe()->only(self::FIELDS);
        $data['type'] ??= 'web_form';

        $source = LeadSource::create(array_merge(['is_active' => true], $data, ['tenant_id' => $this->tenantId()]));
        $plain = $this->credentials->rotate($source, 'both', $this->actorId($request));

        return $this->withCredentials($source, $plain, 201);
    }

    #[OA\Get(
        path: '/api/v1/crm/lead-sources/{id}',
        operationId: 'showCrmLeadSource',
        summary: 'Show lead source',
        tags: ['CRM'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Lead source')]
    )]
    public function show(string $id): JsonResponse
    {
        $source = $this->findSource($id);
        $this->authorize('view', $source);

        return (new LeadSourceResource($source))->response();
    }

    #[OA\Put(
        path: '/api/v1/crm/lead-sources/{id}',
        operationId: 'updateCrmLeadSource',
        summary: 'Update lead source',
        tags: ['CRM'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Lead source updated')]
    )]
    public function update(StoreLeadSourceRequest $request, string $id): JsonResponse
    {
        $source = $this->findSource($id);
        $this->authorize('update', $source);

        $source->update($request->safe()->only(self::FIELDS));

        return (new LeadSourceResource($source))->response();
    }

    #[OA\Delete(
        path: '/api/v1/crm/lead-sources/{id}',
        operationId: 'deleteCrmLeadSource',
        summary: 'Delete lead source',
        tags: ['CRM'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 204, description: 'Lead source deleted')]
    )]
    public function destroy(string $id): JsonResponse
    {
        $source = $this->findSource($id);
        $this->authorize('delete', $source);

        $source->delete();

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/crm/lead-sources/{id}/rotate-secret',
        operationId: 'rotateCrmLeadSourceSecret',
        summary: 'Rotate lead source secret',
        tags: ['CRM'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Secret rotated')]
    )]
    public function rotateSecret(RotateLeadSourceSecretRequest $request, string $id): JsonResponse
    {
        $source = $this->findSource($id);
        $this->authorize('update', $source);

        /** @var 'both'|'webhook_secret'|'api_key' $which */
        $which = (string) ($request->validated('rotate') ?? 'both');
        $plain = $this->credentials->rotate($source, $which, $this->actorId($request));

        return $this->withCredentials($source, $plain, 200);
    }

    /**
     * @param  array{webhook_secret?: string, api_key?: string}  $plain
     */
    private function withCredentials(LeadSource $source, array $plain, int $status): JsonResponse
    {
        $data = (new LeadSourceResource($source))->resolve(request());

        return response()->json(['data' => array_merge($data, $plain)], $status);
    }

    private function findSource(string $id): LeadSource
    {
        return LeadSource::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }
}
