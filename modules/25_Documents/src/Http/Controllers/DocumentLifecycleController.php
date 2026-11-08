<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Documents\Exceptions\DuplicateDocumentException;
use Modules\Documents\Http\Requests\CheckInDocumentRequest;
use Modules\Documents\Http\Requests\CheckOutDocumentRequest;
use Modules\Documents\Http\Resources\DocumentResource;
use Modules\Documents\Services\DocumentAccess;
use Modules\Documents\Services\DocumentLibrary;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Check-out and check-in of documents, and their audit trail.
 */
final class DocumentLifecycleController extends Controller
{
    use ApiResponse;

    /**
     * Fields of the document row that never appear in the trail: they are derived search data.
     *
     * @var list<string>
     */
    private const array HiddenFields = ['search_text', 'search_vector'];

    /**
     * Inject the collaborators.
     */
    public function __construct(
        private readonly DocumentAccess $access,
        private readonly DocumentLibrary $library,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Check a document out.
     */
    #[OA\Post(
        path: '/api/v1/dms/documents/{id}/check-out',
        operationId: 'checkOutDmsDocument',
        description: 'Take the document out for editing. While it is checked out nobody else can add a version, change its metadata or delete it; the holder can. Asking again as the holder changes nothing. A document under legal hold cannot be checked out.',
        summary: 'Check out a document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/DmsCheckOutRequest')),
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Checked out', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsDocumentResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found or not visible to this role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Checked out by somebody else, or under legal hold', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function checkOut(CheckOutDocumentRequest $request, string $id): JsonResponse
    {
        $document = $this->library->checkOut($this->access->findVisible($id, $request->user()), $request->user(), $request->validated('note'));

        return $this->successResponse(new DocumentResource($document), 'Document checked out');
    }

    /**
     * Check a document in.
     */
    #[OA\Post(
        path: '/api/v1/dms/documents/{id}/check-in',
        operationId: 'checkInDmsDocument',
        description: 'Give the document back. With an edited `file` a new version is added in the same step and the earlier versions stay untouched; without one the document is released as it is. A file equal to the current version answers 409 and the document stays checked out. Only the holder can check in.',
        summary: 'Check in a document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(properties: [new OA\Property(property: 'file', type: 'string', format: 'binary', nullable: true), new OA\Property(property: 'comment', type: 'string', maxLength: 500, nullable: true)]))),
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Checked in', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsDocumentResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found or not visible to this role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Not checked out, held by somebody else, or the file equals the current version', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function checkIn(CheckInDocumentRequest $request, string $id): JsonResponse
    {
        $document = $this->access->findVisible($id, $request->user());

        try {
            $document = $this->library->checkIn($document, $request->user(), $request->file('file'), $request->validated('comment'));
        } catch (DuplicateDocumentException $exception) {
            return $this->problemDetailsResponse('Duplicate version', $exception->getMessage(), 409);
        }

        return $this->successResponse(new DocumentResource($document->load('versions')), 'Document checked in');
    }

    /**
     * Cancel a check-out.
     */
    #[OA\Delete(
        path: '/api/v1/dms/documents/{id}/check-out',
        operationId: 'cancelCheckOutDmsDocument',
        description: 'Drop the check-out without a new version. The holder can; owners and administrators can release a document held by somebody who is not available. The release is recorded in the audit trail.',
        summary: 'Cancel a check-out',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Released', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsDocumentResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found or not visible to this role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Not checked out, or held by somebody else and the caller is not a manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function cancelCheckOut(Request $request, string $id): JsonResponse
    {
        $document = $this->library->cancelCheckOut($this->access->findVisible($id, $request->user()), $request->user(), $this->access->isManager($request->user()));

        return $this->successResponse(new DocumentResource($document), 'Check-out cancelled');
    }

    /**
     * Audit trail of a document.
     */
    #[OA\Get(
        path: '/api/v1/dms/documents/{id}/audit-trail',
        operationId: 'getDmsDocumentAuditTrail',
        description: 'Who did what to the document and when, oldest first, from the immutable audit ledger: creation, changes, new versions, downloads, check-outs and check-ins, links, legal holds and deletion. Visible to owners and administrators and to the person who created the document.',
        summary: 'Document audit trail',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Audit entries', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DmsAuditEntry'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Neither a manager nor the creator of the document', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found or not visible to this role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function auditTrail(Request $request, string $id): JsonResponse
    {
        $document = $this->access->findVisible($id, $request->user());

        if (! $this->access->isManager($request->user()) && (string) $document->created_by !== (string) $request->user()->id) {
            throw new AccessDeniedHttpException('Only owners, administrators and the creator of the document can read its audit trail.');
        }

        $entries = [];

        /** @var AuditLedgerEntry $entry */
        foreach (AuditLedgerEntry::query()->where('tenant_id', (string) $this->tenantContext->getTenantId())->where('resource_id', (string) $document->id)->orderBy('event_timestamp')->limit(1000)->get() as $entry) {
            $entries[] = [
                'action' => (string) $entry->action,
                'actor_user_id' => $entry->actor_user_id,
                'occurred_at' => Carbon::parse($entry->event_timestamp)->toIso8601String(),
                'ip_address' => $entry->ip_address,
                'details' => (object) array_diff_key((array) ($entry->payload_after ?? []), array_flip(self::HiddenFields)),
            ];
        }

        return $this->successResponse($entries, 'Audit trail');
    }
}
