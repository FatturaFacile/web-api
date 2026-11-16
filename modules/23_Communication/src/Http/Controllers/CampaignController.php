<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Communication\Http\Controllers\Concerns\InteractsWithCommunication;
use Modules\Communication\Http\Requests\CampaignRequest;
use Modules\Communication\Http\Requests\ScheduleCampaignRequest;
use Modules\Communication\Http\Resources\CampaignRecipientResource;
use Modules\Communication\Http\Resources\CampaignResource;
use Modules\Communication\Models\CampaignRecipient;
use Modules\Communication\Models\CommunicationCampaign;
use Modules\Communication\Services\CampaignService;
use OpenApi\Attributes as OA;

/**
 * Campaigns to the audience of a CRM segment, with scheduling and the send log.
 */
final class CampaignController extends Controller
{
    use InteractsWithCommunication;

    public function __construct(private readonly CampaignService $campaigns) {}

    #[OA\Get(path: '/api/v1/communication/campaigns', operationId: 'listCommunicationCampaigns', description: 'Campaigns, latest first. Filters: status, channel.', summary: 'List campaigns', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Communication Campaigns'], responses: [
        new OA\Response(response: 200, description: 'Campaigns', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CommunicationCampaign'))])),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CommunicationCampaign::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in(CommunicationCampaign::Statuses)], 'channel' => ['nullable', 'string', 'max:20']]);

        return CampaignResource::collection(
            CommunicationCampaign::query()
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['channel']), static fn ($query) => $query->where('channel', $filters['channel']))
                ->latest()
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/communication/campaigns', operationId: 'storeCommunicationCampaign', description: 'Creates a draft campaign on a channel for the audience of a CRM segment. The message accepts the {{ name }} placeholder.', summary: 'Create a campaign', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CommunicationCampaignRequest')), tags: ['Communication Campaigns'], responses: [
        new OA\Response(response: 201, description: 'Campaign created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationCampaign')])),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnprocessable', response: 422),
    ])]
    public function store(CampaignRequest $request): JsonResponse
    {
        $this->authorize('create', CommunicationCampaign::class);

        return (new CampaignResource($this->campaigns->create($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/communication/campaigns/{id}', operationId: 'showCommunicationCampaign', description: 'A campaign with its counters.', summary: 'Show a campaign', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Communication Campaigns'], parameters: [new OA\Parameter(ref: '#/components/parameters/CommunicationInboxId')], responses: [
        new OA\Response(response: 200, description: 'Campaign', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationCampaign')])),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CommunicationInboxNotFound', response: 404),
    ])]
    public function show(string $id): CampaignResource
    {
        $campaign = CommunicationCampaign::query()->findOrFail($id);
        $this->authorize('view', $campaign);

        return new CampaignResource($campaign);
    }

    #[OA\Put(path: '/api/v1/communication/campaigns/{id}', operationId: 'updateCommunicationCampaign', description: 'Changes a draft or scheduled campaign. Sent or running campaigns are locked.', summary: 'Update a campaign', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CommunicationCampaignRequest')), tags: ['Communication Campaigns'], parameters: [new OA\Parameter(ref: '#/components/parameters/CommunicationInboxId')], responses: [
        new OA\Response(response: 200, description: 'Campaign updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationCampaign')])),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CommunicationInboxNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnprocessable', response: 422),
    ])]
    public function update(CampaignRequest $request, string $id): CampaignResource
    {
        $campaign = CommunicationCampaign::query()->findOrFail($id);
        $this->authorize('update', $campaign);

        return new CampaignResource($this->campaigns->update($campaign, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/communication/campaigns/{id}', operationId: 'deleteCommunicationCampaign', description: 'Deletes a draft, scheduled or cancelled campaign (soft delete). A campaign that already sent keeps its send log.', summary: 'Delete a campaign', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Communication Campaigns'], parameters: [new OA\Parameter(ref: '#/components/parameters/CommunicationInboxId')], responses: [
        new OA\Response(response: 204, description: 'Campaign deleted'),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CommunicationInboxNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $campaign = CommunicationCampaign::query()->findOrFail($id);
        $this->authorize('delete', $campaign);
        $this->campaigns->delete($campaign);

        return response()->noContent();
    }

    #[OA\Get(path: '/api/v1/communication/campaigns/{id}/preview', operationId: 'previewCommunicationCampaignAudience', description: 'Evaluates the segment now and counts who would receive the campaign and who would be suppressed, by reason (no_address, opted_out, consent_withdrawn, no_consent). Nothing is sent.', summary: 'Preview the audience', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Communication Campaigns'], parameters: [new OA\Parameter(ref: '#/components/parameters/CommunicationInboxId')], responses: [
        new OA\Response(response: 200, description: 'Audience preview', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationCampaignPreview')])),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CommunicationInboxNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnprocessable', response: 422),
    ])]
    public function preview(string $id): JsonResponse
    {
        $campaign = CommunicationCampaign::query()->findOrFail($id);
        $this->authorize('view', $campaign);

        return response()->json(['data' => $this->campaigns->preview($campaign)]);
    }

    #[OA\Post(path: '/api/v1/communication/campaigns/{id}/schedule', operationId: 'scheduleCommunicationCampaign', description: 'Schedules the campaign at a future time; the due-campaign job starts it. A segment is required.', summary: 'Schedule a campaign', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CommunicationScheduleRequest')), tags: ['Communication Campaigns'], parameters: [new OA\Parameter(ref: '#/components/parameters/CommunicationInboxId')], responses: [
        new OA\Response(response: 200, description: 'Campaign scheduled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationCampaign')])),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CommunicationInboxNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnprocessable', response: 422),
    ])]
    public function schedule(ScheduleCampaignRequest $request, string $id): CampaignResource
    {
        $campaign = CommunicationCampaign::query()->findOrFail($id);
        $this->authorize('update', $campaign);

        return new CampaignResource($this->campaigns->schedule($campaign, $request->validated('scheduled_at')));
    }

    #[OA\Post(path: '/api/v1/communication/campaigns/{id}/send', operationId: 'sendCommunicationCampaign', description: 'Starts the campaign now: builds the audience from the segment, suppresses opted-out and non-consenting members, sends to the rest through the channel driver and fills the send log.', summary: 'Send a campaign now', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Communication Campaigns'], parameters: [new OA\Parameter(ref: '#/components/parameters/CommunicationInboxId')], responses: [
        new OA\Response(response: 200, description: 'Campaign processed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationCampaign')])),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CommunicationInboxNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnprocessable', response: 422),
    ])]
    public function send(string $id): CampaignResource
    {
        $campaign = CommunicationCampaign::query()->findOrFail($id);
        $this->authorize('update', $campaign);

        return new CampaignResource($this->campaigns->sendNow($campaign));
    }

    #[OA\Post(path: '/api/v1/communication/campaigns/{id}/cancel', operationId: 'cancelCommunicationCampaign', description: 'Cancels a draft or scheduled campaign.', summary: 'Cancel a campaign', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Communication Campaigns'], parameters: [new OA\Parameter(ref: '#/components/parameters/CommunicationInboxId')], responses: [
        new OA\Response(response: 200, description: 'Campaign cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationCampaign')])),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CommunicationInboxNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnprocessable', response: 422),
    ])]
    public function cancel(string $id): CampaignResource
    {
        $campaign = CommunicationCampaign::query()->findOrFail($id);
        $this->authorize('update', $campaign);

        return new CampaignResource($this->campaigns->cancel($campaign));
    }

    #[OA\Get(path: '/api/v1/communication/campaigns/{id}/recipients', operationId: 'listCommunicationCampaignRecipients', description: 'The send log of a campaign. Filter: status (pending, sent, failed, suppressed).', summary: 'List the send log', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Communication Campaigns'], parameters: [new OA\Parameter(ref: '#/components/parameters/CommunicationInboxId')], responses: [
        new OA\Response(response: 200, description: 'Recipients', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CommunicationCampaignRecipient'))])),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CommunicationInboxNotFound', response: 404),
    ])]
    public function recipients(Request $request, string $id): AnonymousResourceCollection
    {
        $campaign = CommunicationCampaign::query()->findOrFail($id);
        $this->authorize('view', $campaign);
        $filters = $request->validate(['status' => ['nullable', Rule::in(CampaignRecipient::Statuses)]]);

        return CampaignRecipientResource::collection(
            CampaignRecipient::query()
                ->where('campaign_id', $campaign->id)
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->orderBy('id')
                ->paginate($this->perPage()),
        );
    }
}
