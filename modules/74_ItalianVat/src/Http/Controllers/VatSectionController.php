<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ItalianVat\Http\Controllers\Concerns\ResolvesItalianVatContext;
use Modules\ItalianVat\Http\Requests\StoreSectionRequest;
use Modules\ItalianVat\Http\Requests\UpdateSectionRequest;
use Modules\ItalianVat\Http\Resources\VatSectionResource;
use Modules\ItalianVat\Services\VatSectionService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The sectionals (sezionali) of the VAT registers.
 */
final class VatSectionController extends Controller
{
    use ApiResponse;
    use ResolvesItalianVatContext;

    public function __construct(private readonly VatSectionService $sections) {}

    #[OA\Get(path: '/api/v1/italian-vat/sections', operationId: 'listItalianVatSections', summary: 'List the sectionals of the VAT registers', description: 'The standard sectionals (ordinary, deferred, credit notes, self-invoices, purchases, receipts, third-party goods) are created on first use.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'register_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['sales', 'purchases', 'receipts', 'third_party_goods']))], responses: [new OA\Response(response: 200, description: 'The sectionals')])]
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('register_type');

        return $this->successResponse(VatSectionResource::collection($this->sections->list($this->tenantId(), is_string($type) ? $type : null)));
    }

    #[OA\Post(path: '/api/v1/italian-vat/sections', operationId: 'createItalianVatSection', summary: 'Create a sectional', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], responses: [new OA\Response(response: 201, description: 'The sectional'), new OA\Response(response: 422, description: 'Validation error')])]
    public function store(StoreSectionRequest $request): JsonResponse
    {
        return $this->successResponse(new VatSectionResource($this->sections->create($this->tenantId(), $request->validated())), 'Section created', Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/italian-vat/sections/{id}', operationId: 'updateItalianVatSection', summary: 'Rename or deactivate a sectional', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The sectional'), new OA\Response(response: 404, description: 'Unknown sectional')])]
    public function update(UpdateSectionRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new VatSectionResource($this->sections->update($this->tenantId(), $id, $request->validated())), 'Section updated');
    }
}
