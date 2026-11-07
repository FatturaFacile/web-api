<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreAttachmentRequest;
use App\Http\Resources\CatalogResource;
use App\Http\Responses\ApiResponse;
use App\Models\Attachment;
use App\Models\Expense;
use App\Services\Documents\RecordAccess;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Documents\Models\DmsDocumentVersion;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RecordAccess $recordAccess,
    ) {}

    /**
     * List the files of one record: `attachable_type` and `attachable_id` are required.
     */
    #[OA\Get(
        path: '/api/v1/attachments',
        operationId: 'getAttachments',
        description: 'List attached files for a given entity (invoice, quote, order, contact, etc.).',
        summary: 'List attachments',
        security: [['bearerAuth' => []]],
        tags: ['Attachments', 'Documents'],
        parameters: [
            new OA\Parameter(name: 'attachable_type', in: 'query', required: true, description: 'Type of entity', schema: new OA\Schema(type: 'string', enum: ['invoice', 'quote', 'order', 'delivery_note', 'work_order', 'contact', 'expense', 'passive_invoice'])),
            new OA\Parameter(name: 'attachable_id', in: 'query', required: true, description: 'UUID of entity', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Attachments listed successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'attachable_type' => ['required', 'in:'.implode(',', array_keys(Attachment::TARGET_TYPES))],
            'attachable_id' => ['required', 'uuid'],
        ]);

        $class = Attachment::TARGET_TYPES[$request->query('attachable_type')];
        $this->recordAccess->authorize($request->user(), $class::findOrFail($request->query('attachable_id')), 'read');

        $attachments = Attachment::where('attachable_type', $class)
            ->where('attachable_id', $request->query('attachable_id'))
            ->latest()
            ->get();

        return $this->successResponse(CatalogResource::collection($attachments), __('documents.messages.attachments'));
    }

    /**
     * Upload a file to the private disk and link it to a contact or document of the tenant.
     */
    #[OA\Post(
        path: '/api/v1/attachments',
        operationId: 'uploadAttachment',
        description: 'Upload a file and attach it to a specific entity.',
        summary: 'Upload attachment',
        security: [['bearerAuth' => []]],
        tags: ['Attachments', 'Documents'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file', 'attachable_type', 'attachable_id'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary'),
                        new OA\Property(property: 'attachable_type', type: 'string', enum: ['invoice', 'quote', 'order', 'delivery_note', 'work_order', 'contact', 'expense', 'passive_invoice']),
                        new OA\Property(property: 'attachable_id', type: 'string', format: 'uuid'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Attachment uploaded successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreAttachmentRequest $request): JsonResponse
    {
        $class = Attachment::TARGET_TYPES[$request->validated('attachable_type')];
        $target = $class::findOrFail($request->validated('attachable_id'));
        $this->recordAccess->authorize($request->user(), $target, 'write', $this->writeAbility($target));

        $disk = (string) config('filesystems.private_disk', config('filesystems.default', 'local'));
        $file = $request->file('file');
        $path = $file->storeAs(
            "tenants/{$this->tenantContext->getTenantId()}/attachments",
            Str::uuid().'-'.Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.($file->guessExtension() ?: 'bin'),
            $disk
        );

        $attachment = Attachment::create([
            'attachable_type' => $target->getMorphClass(),
            'attachable_id' => $target->getKey(),
            'uploaded_by' => Auth::id(),
            'original_name' => $file->getClientOriginalName(),
            'disk' => $disk,
            'path' => $path,
            'mime_type' => (string) $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        return $this->successResponse(new CatalogResource($attachment), __('documents.messages.attachment_stored'), Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/attachments/{id}/download',
        operationId: 'downloadAttachmentFile',
        description: 'Download the physical attachment file from storage.',
        summary: 'Download attachment file',
        security: [['bearerAuth' => []]],
        tags: ['Attachments', 'Documents'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Attachment UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Binary file download', content: new OA\MediaType(mediaType: 'application/octet-stream')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Attachment not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function download(Request $request, string $id): StreamedResponse
    {
        $attachment = Attachment::findOrFail($id);
        $this->recordAccess->authorize($request->user(), $this->parentOf($attachment), 'read');

        return Storage::disk($attachment->getStorageDisk())->download($attachment->path, $attachment->original_name);
    }

    #[OA\Delete(
        path: '/api/v1/attachments/{id}',
        operationId: 'deleteAttachment',
        description: 'Delete an attachment file and its metadata record.',
        summary: 'Delete attachment',
        security: [['bearerAuth' => []]],
        tags: ['Attachments', 'Documents'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Attachment UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Attachment deleted successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Attachment not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'A managed document references this file', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $attachment = Attachment::findOrFail($id);
        $parent = $this->parentOf($attachment);
        $this->recordAccess->authorize($request->user(), $parent, 'write', $this->writeAbility($parent));

        if (DmsDocumentVersion::query()->where('attachment_id', $attachment->id)->exists()) {
            return $this->problemDetailsResponse('Attachment in use', 'A managed document references this file: delete the document first.', Response::HTTP_CONFLICT);
        }

        Storage::disk($attachment->getStorageDisk())->delete($attachment->path);
        $attachment->delete();

        return $this->successResponse(null, __('documents.messages.attachment_deleted'));
    }

    /**
     * The record the attachment belongs to, in the active tenant; a file whose record is gone is not served.
     */
    private function parentOf(Attachment $attachment): Model
    {
        $parent = $attachment->attachable;

        abort_unless($parent instanceof Model, Response::HTTP_NOT_FOUND);

        return $parent;
    }

    /**
     * Adding or removing a file changes the record: expenses have their own ability for it, which ignores the export
     * lock; every other record only needs to be visible once the module grants write access.
     */
    private function writeAbility(Model $record): string
    {
        return $record instanceof Expense ? 'uploadAttachment' : 'view';
    }
}
