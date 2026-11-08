<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Communication\Http\Requests\SaveTemplateRequest;
use Modules\Communication\Http\Requests\ScalarText;
use Modules\Communication\Models\CommunicationTemplate;
use Modules\Communication\Services\TemplateRenderer;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Message templates of the company, one per code, channel and language.
 */
final class TemplateController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly TemplateRenderer $renderer) {}

    /**
     * List templates.
     */
    #[OA\Get(
        path: '/api/v1/communication/templates',
        operationId: 'listCommunicationTemplates',
        description: 'Message templates of the company by code, channel and language. Filter by `code`, `channel` or `locale`.',
        summary: 'List templates',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Communication Templates'],
        parameters: [
            new OA\Parameter(name: 'code', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'channel', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['email', 'whatsapp', 'telegram', 'sms'])),
            new OA\Parameter(name: 'locale', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'it_CH')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Templates', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CommunicationTemplateResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'The role cannot read the communication module', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $templates = CommunicationTemplate::query()
            ->when($request->query('code'), static fn ($query, string $code) => $query->where('code', $code))
            ->when($request->query('channel'), static fn ($query, string $channel) => $query->where('channel', $channel))
            ->when($request->query('locale'), static fn ($query, string $locale) => $query->where('locale', $locale))
            ->orderBy('code')->orderBy('channel')->orderBy('locale')
            ->get();

        return $this->successResponse($templates->map(fn (CommunicationTemplate $template): array => $this->present($template))->all(), 'Templates retrieved');
    }

    /**
     * Create a template.
     */
    #[OA\Post(
        path: '/api/v1/communication/templates',
        operationId: 'createCommunicationTemplate',
        description: 'Creates the template of a code for one channel and language. Placeholders are written `{{ name }}` with a lower-case name; no other markup or code is interpreted. The same code, channel and language can exist only once. The codes `document.invoice` and `document.quote` are used when sending documents (variables: number, note, contact_name, company_name).',
        summary: 'Create a template',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CommunicationSaveTemplateRequest')),
        tags: ['Communication Templates'],
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationTemplateResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'The role cannot write the communication module', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(SaveTemplateRequest $request): JsonResponse
    {
        $template = CommunicationTemplate::create([...$request->validated(), 'is_active' => $request->boolean('is_active', true), 'updated_by' => $request->user()?->id]);

        return $this->successResponse($this->present($template), 'Template created', Response::HTTP_CREATED);
    }

    /**
     * Show a template.
     */
    #[OA\Get(
        path: '/api/v1/communication/templates/{id}',
        operationId: 'showCommunicationTemplate',
        description: 'A template with the placeholders it needs.',
        summary: 'Show a template',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Communication Templates'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The template', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationTemplateResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'The role cannot read the communication module', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse($this->present(CommunicationTemplate::query()->findOrFail($id)));
    }

    /**
     * Update a template.
     */
    #[OA\Put(
        path: '/api/v1/communication/templates/{id}',
        operationId: 'updateCommunicationTemplate',
        description: 'Replaces the content of a template. Sends already made keep what they logged: the log stores the subject and the template code, not the body.',
        summary: 'Update a template',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CommunicationSaveTemplateRequest')),
        tags: ['Communication Templates'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationTemplateResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'The role cannot write the communication module', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(SaveTemplateRequest $request, string $id): JsonResponse
    {
        $template = CommunicationTemplate::query()->findOrFail($id);
        $template->update([...$request->validated(), 'updated_by' => $request->user()?->id]);

        return $this->successResponse($this->present($template), 'Template updated');
    }

    /**
     * Delete a template.
     */
    #[OA\Delete(
        path: '/api/v1/communication/templates/{id}',
        operationId: 'deleteCommunicationTemplate',
        description: 'Deletes a template. Messages of that code fall back to another language, or to the standard wording for documents.',
        summary: 'Delete a template',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Communication Templates'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'The role cannot write the communication module', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        CommunicationTemplate::query()->findOrFail($id)->delete();

        return $this->successResponse(null, 'Template deleted');
    }

    /**
     * Preview a template.
     */
    #[OA\Post(
        path: '/api/v1/communication/templates/{id}/preview',
        operationId: 'previewCommunicationTemplate',
        description: 'Fills in the template with the given variables and returns the subject and body, without sending anything. Every placeholder needs a variable.',
        summary: 'Preview a template',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'variables', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string'), example: ['name' => 'Rossi SA', 'number' => 'INV-2027-0042'])])),
        tags: ['Communication Templates'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The rendered message', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'subject', type: 'string', nullable: true), new OA\Property(property: 'body', type: 'string')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'The role cannot write the communication module', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'A placeholder has no variable', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function preview(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['variables' => ['nullable', 'array', 'max:50'], 'variables.*' => ['nullable', new ScalarText(1000)]]);

        return $this->successResponse($this->renderer->render(CommunicationTemplate::query()->findOrFail($id), $data['variables'] ?? []), 'Template rendered');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CommunicationTemplate $template): array
    {
        return [
            'id' => (string) $template->id,
            'code' => $template->code,
            'channel' => $template->channel,
            'locale' => $template->locale,
            'subject' => $template->subject,
            'body' => $template->body,
            'is_active' => (bool) $template->is_active,
            'placeholders' => array_values(array_unique([...$this->renderer->placeholders((string) $template->subject), ...$this->renderer->placeholders($template->body)])),
        ];
    }
}
