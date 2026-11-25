<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\ElectronicSignature\Http\Requests\SaveTemplateRequest;
use Modules\ElectronicSignature\Http\Resources\TemplateResource;
use Modules\ElectronicSignature\Models\SignatureTemplate;
use OpenApi\Attributes as OA;

/**
 * Signature templates: reusable workflows that, with a threshold, also act as compliance rules (e.g. purchase orders above
 * 5,000 need an advanced signature).
 */
final class TemplateController extends Controller
{
    use ApiResponse;

    #[OA\Get(path: '/api/v1/signatures/templates', operationId: 'signaturesListTemplates', summary: 'List the signature templates', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], responses: [new OA\Response(response: 200, description: 'Templates')])]
    public function index(): JsonResponse
    {
        Gate::authorize('signatures.view');

        return $this->successResponse(TemplateResource::collection(SignatureTemplate::query()->orderBy('document_type')->orderBy('min_amount')->get())->resolve());
    }

    #[OA\Post(path: '/api/v1/signatures/templates', operationId: 'signaturesCreateTemplate', summary: 'Create a signature template', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], responses: [new OA\Response(response: 201, description: 'Template created')])]
    public function store(SaveTemplateRequest $request): JsonResponse
    {
        return $this->successResponse(new TemplateResource(SignatureTemplate::query()->create($request->validated())), 'signatures.message.saved', 201);
    }

    #[OA\Put(path: '/api/v1/signatures/templates/{id}', operationId: 'signaturesUpdateTemplate', summary: 'Update a signature template', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Template updated')])]
    public function update(SaveTemplateRequest $request, string $id): JsonResponse
    {
        $template = SignatureTemplate::query()->findOrFail($id);
        $template->update($request->validated());

        return $this->successResponse(new TemplateResource($template->refresh()), 'signatures.message.saved');
    }

    #[OA\Delete(path: '/api/v1/signatures/templates/{id}', operationId: 'signaturesDeleteTemplate', summary: 'Delete a signature template', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Template deleted')])]
    public function destroy(string $id): JsonResponse
    {
        Gate::authorize('signatures.configure');
        SignatureTemplate::query()->findOrFail($id)->delete();

        return $this->successResponse(null, 'signatures.message.deleted');
    }
}
