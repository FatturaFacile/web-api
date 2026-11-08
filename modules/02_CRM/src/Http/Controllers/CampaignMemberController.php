<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Http\Requests\StoreCampaignMemberRequest;
use Modules\CRM\Models\Campaign;
use Modules\CRM\Models\CampaignMember;
use Modules\CRM\Models\Segment;
use Modules\CRM\Services\CampaignAudienceService;
use OpenApi\Attributes as OA;

/**
 * Campaign audience (members from contacts, leads or segments) and consent-aware delivery.
 */
final class CampaignMemberController extends Controller
{
    public function __construct(
        private readonly CampaignAudienceService $audienceService,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Lists the members of a campaign.
     */
    #[OA\Get(
        path: '/api/v1/crm/campaigns/{id}/members',
        operationId: 'listCrmCampaignMembers',
        description: 'Lists the members of a campaign with their delivery status (planned, sent, suppressed with reason, responded, converted).',
        summary: 'List campaign members',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Marketing'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the campaign', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Members', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMCampaignMember')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request, string $id): JsonResponse
    {
        $campaign = $this->campaign($id);
        $members = CampaignMember::where('campaign_id', $campaign->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->orderBy('created_at')
            ->paginate(50);

        return response()->json([
            'data' => $members->getCollection()->map(fn (CampaignMember $member): array => $this->present($member))->values(),
            'meta' => ['current_page' => $members->currentPage(), 'total' => $members->total()],
        ]);
    }

    /**
     * Adds a contact, a lead or a whole segment to a campaign.
     */
    #[OA\Post(
        path: '/api/v1/crm/campaigns/{id}/members',
        operationId: 'addCrmCampaignMembers',
        description: 'Adds one contact, one lead, or every current member of a segment to the campaign. Existing members are not duplicated.',
        summary: 'Add campaign members',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Marketing'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the campaign', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CRMStoreCampaignMemberRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Members added', content: new OA\JsonContent(properties: [new OA\Property(property: 'added', type: 'integer'), new OA\Property(property: 'already_members', type: 'integer'), new OA\Property(property: 'member', ref: '#/components/schemas/CRMCampaignMember', nullable: true)])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreCampaignMemberRequest $request, string $id): JsonResponse
    {
        $campaign = $this->campaign($id);

        if ($request->filled('segment_id')) {
            $segment = Segment::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($request->input('segment_id'));

            return response()->json([...$this->audienceService->addSegment($campaign, $segment), 'member' => null], 201);
        }

        $member = $this->audienceService->addMember($campaign, $request->input('contact_id'), $request->input('lead_id'));

        return response()->json([
            'added' => $member->wasRecentlyCreated ? 1 : 0,
            'already_members' => $member->wasRecentlyCreated ? 0 : 1,
            'member' => $this->present($member),
        ], 201);
    }

    /**
     * Removes a member from a campaign.
     */
    #[OA\Delete(
        path: '/api/v1/crm/campaigns/{id}/members/{memberId}',
        operationId: 'removeCrmCampaignMember',
        description: 'Removes a member from the campaign.',
        summary: 'Remove campaign member',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Marketing'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the campaign', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'memberId', in: 'path', required: true, description: 'Identifier of the member', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Removed'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id, string $memberId): JsonResponse
    {
        $campaign = $this->campaign($id);
        CampaignMember::where('campaign_id', $campaign->id)->findOrFail($memberId)->delete();

        return response()->json(null, 204);
    }

    /**
     * Emails the campaign to the members who opted in.
     */
    #[OA\Post(
        path: '/api/v1/crm/campaigns/{id}/send',
        operationId: 'sendCrmCampaign',
        description: 'Emails the campaign message to the members not yet contacted. Only members with a current email marketing opt-in are emailed; the others are marked suppressed with the reason (opted_out, no_consent, no_email). Repeating the call does not email anyone twice.',
        summary: 'Send campaign',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Marketing'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the campaign', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CRMSendCampaignRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Delivery result', content: new OA\JsonContent(ref: '#/components/schemas/CRMCampaignSendResult')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function send(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        $campaign = $this->campaign($id);
        $result = $this->audienceService->send($campaign, $validated['subject'], $validated['body'], $request->user()?->id !== null ? (string) $request->user()->id : null);

        return response()->json($result);
    }

    private function campaign(string $id): Campaign
    {
        return Campaign::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CampaignMember $member): array
    {
        return [
            'id' => (string) $member->id,
            'campaign_id' => (string) $member->campaign_id,
            'contact_id' => $member->contact_id,
            'lead_id' => $member->lead_id,
            'status' => $member->status,
            'suppression_reason' => $member->suppression_reason,
            'sent_at' => $member->sent_at?->toIso8601String(),
            'first_associated_at' => $member->first_associated_at?->toIso8601String(),
        ];
    }
}
