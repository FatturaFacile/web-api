<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\DocumentTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class DocumentTemplateController extends Controller
{
    use ApiResponse;

    private const int MaxLogoBytes = 524288;

    /**
     * @var array<int, string>
     */
    private const array LogoTypes = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg'];

    #[OA\Get(path: '/api/v1/settings/document-templates', operationId: 'listDocumentTemplates', description: 'Branding of the PDF documents: the `default` row and the overrides per document type.', summary: 'List document templates', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Settings'], responses: [
        new OA\Response(response: 200, description: 'The templates', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
    ])]
    public function index(): JsonResponse
    {
        return $this->successResponse(DocumentTemplate::query()->orderBy('document_type')->get(), 'Document templates retrieved');
    }

    #[OA\Put(path: '/api/v1/settings/document-templates/{type}', operationId: 'saveDocumentTemplate', description: 'Saves the branding of a document type, or of `default`. Colours are #RRGGBB. The logo is a base64 PNG or JPEG of at most 512 KB. The footer is plain text: markup is removed and it is never compiled as a template.', summary: 'Save document template', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'logo_base64', type: 'string', nullable: true),
        new OA\Property(property: 'primary_color', type: 'string', example: '#112233', nullable: true),
        new OA\Property(property: 'secondary_color', type: 'string', nullable: true),
        new OA\Property(property: 'footer_text', type: 'string', maxLength: 500, nullable: true),
    ])), tags: ['Settings'], parameters: [new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: DocumentTemplate::Types))], responses: [
        new OA\Response(response: 200, description: 'Template saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function update(Request $request, string $type): JsonResponse
    {
        $hexColor = ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];

        validator(['type' => $type], ['type' => ['required', Rule::in(DocumentTemplate::Types)]])->validate();

        $data = $request->validate([
            'logo_base64' => ['nullable', 'string', 'max:800000'],
            'primary_color' => $hexColor,
            'secondary_color' => $hexColor,
            'footer_text' => ['nullable', 'string', 'max:500'],
        ]);

        $template = DocumentTemplate::firstOrNew(['document_type' => $type]);

        foreach (['primary_color', 'secondary_color'] as $field) {
            if (array_key_exists($field, $data)) {
                $template->{$field} = $data[$field];
            }
        }

        if (array_key_exists('footer_text', $data)) {
            $template->footer_text = $data['footer_text'] === null ? null : trim(strip_tags($data['footer_text']));
        }

        if (array_key_exists('logo_base64', $data)) {
            $template->logo_data_uri = $data['logo_base64'] === null ? null : $this->logoDataUri($data['logo_base64']);
        }

        $template->save();

        return $this->successResponse($template, 'Document template saved');
    }

    #[OA\Delete(path: '/api/v1/settings/document-templates/{type}', operationId: 'deleteDocumentTemplate', description: 'Removes an override: the type goes back to the `default` row, or to the standard look.', summary: 'Delete document template', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Settings'], parameters: [new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'Template removed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
    ])]
    public function destroy(string $type): JsonResponse
    {
        DocumentTemplate::where('document_type', $type)->delete();

        return $this->successResponse(null, 'Document template removed');
    }

    /**
     * The real content decides the type, so SVG or a script renamed to .png cannot get through.
     */
    private function logoDataUri(string $encoded): string
    {
        $binary = base64_decode($encoded, true);
        $image = $binary === false || $binary === '' ? false : @getimagesizefromstring($binary);

        if ($image === false || ! isset(self::LogoTypes[$image[2]]) || strlen((string) $binary) > self::MaxLogoBytes) {
            throw ValidationException::withMessages(['logo_base64' => __('sales.document_template_invalid_logo')]);
        }

        return 'data:'.self::LogoTypes[$image[2]].';base64,'.base64_encode((string) $binary);
    }
}
