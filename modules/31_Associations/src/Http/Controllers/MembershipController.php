<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Associations\Http\Requests\MembershipReasonRequest;
use Modules\Associations\Http\Requests\RegisterBadgeRequest;
use Modules\Associations\Http\Requests\RenewMembershipRequest;
use Modules\Associations\Http\Requests\StoreMembershipRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\AssociativeYear;
use Modules\Associations\Models\Credential;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\Membership;
use Modules\Associations\Models\MembershipType;
use Modules\Associations\OpenApi\AssociationDelete;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\Services\CardDocumentService;
use Modules\Associations\Services\CredentialService;
use Modules\Associations\Services\MembershipService;
use Modules\Associations\Support\AssociationCapability;

/**
 * Memberships, cards and credentials.
 */
final class MembershipController extends AssociationController
{
    public function __construct(
        private readonly MembershipService $memberships,
        private readonly CredentialService $credentials,
        private readonly CardDocumentService $cards,
    ) {}

    #[AssociationGet('/api/v1/associations/memberships', 'listAssociationMemberships', 'List the memberships', query: ['year_id', 'status', 'member_id'])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Membership::class);

        $memberships = Membership::query()->with('membershipType')
            ->when($request->filled('year_id'), static fn ($query) => $query->where('year_id', (string) $request->query('year_id')))
            ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
            ->when($request->filled('member_id'), static fn ($query) => $query->where('member_id', (string) $request->query('member_id')))
            ->orderByDesc('valid_to')
            ->gridPaginate($this->perPage($request));

        return AssociationRecordResource::collection($memberships);
    }

    #[AssociationPost('/api/v1/associations/memberships', 'createAssociationMembership', 'Open a membership for a person', 'AssociationMembershipRequest')]
    public function store(StoreMembershipRequest $request): JsonResponse
    {
        $this->authorize('create', Membership::class);

        $membership = $this->memberships->request(
            Member::query()->findOrFail($request->validated('member_id')),
            AssociativeYear::query()->findOrFail($request->validated('year_id')),
            MembershipType::query()->findOrFail($request->validated('membership_type_id')),
        );

        return (new AssociationRecordResource($request->boolean('activate') ? $this->memberships->activate($membership) : $membership))
            ->response()->setStatusCode(201);
    }

    #[AssociationGet('/api/v1/associations/memberships/{id}', 'showAssociationMembership', 'Show a membership')]
    public function show(string $id): AssociationRecordResource
    {
        $membership = Membership::query()->with(['membershipType', 'year'])->findOrFail($id);
        $this->authorize('view', $membership);

        return new AssociationRecordResource($membership);
    }

    #[AssociationPost('/api/v1/associations/memberships/{id}/activate', 'activateAssociationMembership', 'Activate a membership: card number, QR, registry type and fee')]
    public function activate(string $id): AssociationRecordResource
    {
        $membership = Membership::query()->findOrFail($id);
        $this->authorize('update', $membership);

        return new AssociationRecordResource($this->memberships->activate($membership));
    }

    #[AssociationPost('/api/v1/associations/memberships/{id}/renew', 'renewAssociationMembership', 'Renew a membership in a following year, with the same card number', 'AssociationMembershipRenewRequest')]
    public function renew(RenewMembershipRequest $request, string $id): JsonResponse
    {
        $membership = Membership::query()->with(['year', 'membershipType'])->findOrFail($id);
        $this->authorize('update', $membership);
        $typeId = $request->validated('membership_type_id');

        return (new AssociationRecordResource($this->memberships->renew(
            $membership,
            AssociativeYear::query()->findOrFail($request->validated('year_id')),
            $typeId === null ? null : MembershipType::query()->findOrFail($typeId),
            $request->boolean('activate', true),
        )))->response()->setStatusCode(201);
    }

    #[AssociationPost('/api/v1/associations/memberships/{id}/suspend', 'suspendAssociationMembership', 'Suspend a membership. Its credentials stop working', 'AssociationReasonRequest')]
    public function suspend(MembershipReasonRequest $request, string $id): AssociationRecordResource
    {
        $membership = Membership::query()->with('member')->findOrFail($id);
        $this->authorize('update', $membership);

        return new AssociationRecordResource($this->memberships->suspend($membership, $request->validated('reason')));
    }

    #[AssociationPost('/api/v1/associations/memberships/{id}/cease', 'ceaseAssociationMembership', 'End a membership. Its credentials stop working', 'AssociationReasonRequest')]
    public function cease(MembershipReasonRequest $request, string $id): AssociationRecordResource
    {
        $membership = Membership::query()->with('member')->findOrFail($id);
        $this->authorize('update', $membership);

        return new AssociationRecordResource($this->memberships->cease($membership, $request->validated('reason')));
    }

    #[AssociationGet('/api/v1/associations/memberships/{id}/card', 'downloadAssociationCard', 'Download the digital card as PDF', file: true)]
    public function card(string $id): Response
    {
        $membership = Membership::query()->with(['member.contact', 'year', 'membershipType'])->findOrFail($id);
        $this->authorize('view', $membership);

        return $this->pdf($this->cards->render($membership), "card-{$membership->card_number}.pdf");
    }

    #[AssociationPost('/api/v1/associations/members/{id}/qr', 'reissueAssociationQr', 'Issue a new QR, revoking the current one')]
    public function reissueQr(string $id): JsonResponse
    {
        $member = Member::query()->findOrFail($id);
        $this->authorize('update', $member);

        $membership = $member->currentMembership();
        $credential = $this->credentials->issueQr($member, $membership === null ? null : CarbonImmutable::parse($membership->valid_to)->endOfDay());

        return response()->json(['data' => ['credential_id' => $credential->id, 'expires_at' => $credential->expires_at]], 201);
    }

    #[AssociationPost('/api/v1/associations/members/{id}/badges', 'registerAssociationBadge', 'Register a badge for a person', 'AssociationBadgeRequest')]
    public function registerBadge(RegisterBadgeRequest $request, string $id): AssociationRecordResource
    {
        $member = Member::query()->findOrFail($id);
        $this->authorize('update', $member);
        $expiresAt = $request->validated('expires_at');

        return new AssociationRecordResource($this->credentials->registerBadge($member, (string) $request->validated('uid'), $expiresAt === null ? null : CarbonImmutable::parse($expiresAt)));
    }

    #[AssociationDelete('/api/v1/associations/credentials/{id}', 'revokeAssociationCredential', 'Revoke a QR or a badge')]
    public function revokeCredential(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        $this->credentials->revoke(Credential::query()->findOrFail($id), 'revoked by staff');

        return response()->json(null, 204);
    }
}
