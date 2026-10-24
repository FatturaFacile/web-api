<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreAttachmentRequest;
use App\Http\Resources\CatalogResource;
use App\Http\Responses\ApiResponse;
use App\Models\Attachment;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * List the files of one record: `attachable_type` and `attachable_id` are required.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'attachable_type' => ['required', 'in:'.implode(',', array_keys(Attachment::TARGET_TYPES))],
            'attachable_id' => ['required', 'uuid'],
        ]);

        $attachments = Attachment::where('attachable_type', Attachment::TARGET_TYPES[$request->query('attachable_type')])
            ->where('attachable_id', $request->query('attachable_id'))
            ->latest()
            ->get();

        return $this->successResponse(CatalogResource::collection($attachments), __('documents.messages.attachments'));
    }

    /**
     * Upload a file to the private disk and link it to a contact or document of the tenant.
     */
    public function store(StoreAttachmentRequest $request): JsonResponse
    {
        $class = Attachment::TARGET_TYPES[$request->validated('attachable_type')];
        $target = $class::findOrFail($request->validated('attachable_id'));

        $file = $request->file('file');
        $path = $file->storeAs(
            "tenants/{$this->tenantContext->getTenantId()}/attachments",
            Str::uuid().'-'.Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$file->getClientOriginalExtension(),
            'local'
        );

        $attachment = Attachment::create([
            'attachable_type' => $target->getMorphClass(),
            'attachable_id' => $target->getKey(),
            'uploaded_by' => Auth::id(),
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => (string) $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        return $this->successResponse(new CatalogResource($attachment), __('documents.messages.attachment_stored'), Response::HTTP_CREATED);
    }

    public function download(string $id): StreamedResponse
    {
        $attachment = Attachment::findOrFail($id);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name);
    }

    public function destroy(string $id): JsonResponse
    {
        $attachment = Attachment::findOrFail($id);
        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();

        return $this->successResponse(null, __('documents.messages.attachment_deleted'));
    }
}
