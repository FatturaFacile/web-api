<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Associations\Http\Requests\LinkGuardianRequest;
use Modules\Associations\Http\Requests\StoreAuthorizationRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\Authorization;
use Modules\Associations\Models\Guardianship;
use Modules\Associations\Models\Member;
use Modules\Associations\OpenApi\AssociationDelete;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\Services\AreaTokenService;
use Modules\Associations\Services\GuardianService;
use Modules\Associations\Support\AssociationCapability;

/**
 * Minors: guardians, authorizations and the personal links to the member area.
 */
final class GuardianController extends AssociationController
{
    public function __construct(
        private readonly GuardianService $guardians,
        private readonly AreaTokenService $areaTokens,
    ) {}

    #[AssociationGet('/api/v1/associations/members/{id}/guardians', 'listAssociationGuardians', 'List the guardians of a minor')]
    public function index(string $id): AnonymousResourceCollection
    {
        $member = Member::query()->findOrFail($id);
        $this->authorize('view', $member);

        return AssociationRecordResource::collection($member->guardianships()->with('guardian')->get());
    }

    #[AssociationPost('/api/v1/associations/members/{id}/guardians', 'linkAssociationGuardian', 'Link a guardian to a minor', 'AssociationGuardianRequest')]
    public function store(LinkGuardianRequest $request, string $id): AssociationRecordResource
    {
        $member = Member::query()->findOrFail($id);
        $this->authorize('update', $member);

        return new AssociationRecordResource($this->guardians->link($member, $request->validated()));
    }

    #[AssociationDelete('/api/v1/associations/guardians/{id}', 'removeAssociationGuardian', 'Remove a guardian from a minor')]
    public function destroy(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        Guardianship::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    #[AssociationGet('/api/v1/associations/members/{id}/authorizations', 'listAssociationAuthorizations', 'List the authorizations of a minor')]
    public function authorizations(string $id): AnonymousResourceCollection
    {
        $member = Member::query()->findOrFail($id);
        $this->authorize('view', $member);

        return AssociationRecordResource::collection($member->authorizations()->orderByDesc('created_at')->get());
    }

    #[AssociationPost('/api/v1/associations/members/{id}/authorizations', 'requestAssociationAuthorization', 'Ask the guardians of a minor for an authorization', 'AssociationAuthorizationRequest')]
    public function requestAuthorization(StoreAuthorizationRequest $request, string $id): AssociationRecordResource
    {
        $member = Member::query()->findOrFail($id);
        $this->authorize('update', $member);

        return new AssociationRecordResource($this->guardians->requestAuthorization($member, $request->validated()));
    }

    #[AssociationPost('/api/v1/associations/authorizations/{id}/revoke', 'revokeAssociationAuthorization', 'Revoke an authorization')]
    public function revokeAuthorization(string $id): AssociationRecordResource
    {
        $authorization = Authorization::query()->findOrFail($id);
        $this->authorize('update', $authorization);

        return new AssociationRecordResource($this->guardians->revoke($authorization));
    }

    #[AssociationPost('/api/v1/associations/contacts/{id}/area-link', 'sendAssociationAreaLink', 'Send a contact the personal link to the member area')]
    public function sendAreaLink(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        $this->areaTokens->issue(Contact::query()->findOrFail($id));

        return response()->json(['message' => __('associations.area_link_sent')], 202);
    }

    #[AssociationDelete('/api/v1/associations/contacts/{id}/area-link', 'revokeAssociationAreaLink', 'Revoke the personal links of a contact to the member area')]
    public function revokeAreaLink(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        $this->areaTokens->revokeAll(Contact::query()->findOrFail($id));

        return response()->json(null, 204);
    }
}
