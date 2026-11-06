<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Associations\Http\Requests\StoreMemberDocumentRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\MemberDocument;
use Modules\Associations\OpenApi\AssociationDelete;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\Services\PrivateFiles;
use Modules\Associations\Services\RegistryService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The documents of a person, kept on the private disk, and their expiry.
 */
final class MemberDocumentController extends AssociationController
{
    public function __construct(
        private readonly RegistryService $registry,
        private readonly PrivateFiles $files,
    ) {}

    #[AssociationGet('/api/v1/associations/members/{id}/documents', 'listAssociationMemberDocuments', 'List the documents of a person')]
    public function index(string $id): AnonymousResourceCollection
    {
        $member = Member::query()->findOrFail($id);
        $this->authorize('view', $member);

        return AssociationRecordResource::collection($member->documents()->orderByDesc('created_at')->get());
    }

    #[AssociationPost('/api/v1/associations/members/{id}/documents', 'storeAssociationMemberDocument', 'Attach a document to a person', 'AssociationDocumentUploadRequest', multipart: true)]
    public function store(StoreMemberDocumentRequest $request, string $id): AssociationRecordResource
    {
        $member = Member::query()->findOrFail($id);
        $this->authorize('update', $member);

        return new AssociationRecordResource($this->registry->addDocument($member, $request->file('file'), $request->validated(), $this->actorId($request)));
    }

    #[AssociationGet('/api/v1/associations/member-documents/expiring', 'listAssociationExpiringDocuments', 'List the documents expired or expiring within the given days', query: ['days'])]
    public function expiring(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Member::class);
        $limit = CarbonImmutable::today()->addDays(max(0, $request->integer('days', 30)))->toDateString();

        return AssociationRecordResource::collection(MemberDocument::query()->whereNotNull('expires_on')->whereDate('expires_on', '<=', $limit)->orderBy('expires_on')->paginate($this->perPage($request)));
    }

    #[AssociationGet('/api/v1/associations/member-documents/{id}/download', 'downloadAssociationMemberDocument', 'Download a document of a person', file: true)]
    public function download(string $id): StreamedResponse
    {
        $document = MemberDocument::query()->findOrFail($id);
        $this->authorize('view', $document);

        return $this->files->download($document->disk, $document->path, $document->original_name);
    }

    #[AssociationDelete('/api/v1/associations/member-documents/{id}', 'deleteAssociationMemberDocument', 'Delete a document of a person')]
    public function destroy(string $id): JsonResponse
    {
        $document = MemberDocument::query()->findOrFail($id);
        $this->authorize('delete', $document);
        $document->delete();

        return response()->json(null, 204);
    }
}
