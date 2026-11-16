<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Associations\Enums\DeliveryChannel;
use Modules\Associations\Enums\DeliveryMode;
use Modules\Associations\Http\Requests\SendDocumentRequest;
use Modules\Associations\Http\Requests\UpdateMinutesRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\AssociationDelivery;
use Modules\Associations\Models\Meeting;
use Modules\Associations\Models\Minutes;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\OpenApi\AssociationPut;
use Modules\Associations\Services\MeetingDocuments;
use Modules\Associations\Services\MinutesService;
use Modules\Associations\Support\AssociationCapability;

/**
 * Minutes of the meetings: generated, edited while a draft, approved and then immutable, printed and distributed.
 */
final class MinutesController extends AssociationController
{
    public function __construct(private readonly MinutesService $minutes) {}

    #[AssociationPost('/api/v1/associations/meetings/{id}/minutes', 'createAssociationMinutes', 'Generate the draft minutes with attendance, agenda, decisions and ballot results', response: 'AssociationMinutes')]
    public function store(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        $meeting = Meeting::query()->with('agendaItems')->findOrFail($id);

        return (new AssociationRecordResource($this->minutes->draft($meeting)))->response()->setStatusCode(201);
    }

    #[AssociationGet('/api/v1/associations/minutes/{id}', 'showAssociationMinutes', 'Show minutes', response: 'AssociationMinutes')]
    public function show(string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Read);

        return new AssociationRecordResource(Minutes::query()->findOrFail($id));
    }

    #[AssociationPut('/api/v1/associations/minutes/{id}', 'updateAssociationMinutes', 'Edit draft minutes; approved minutes are immutable', 'AssociationMinutesRequest', response: 'AssociationMinutes')]
    public function update(UpdateMinutesRequest $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);

        return new AssociationRecordResource($this->minutes->update(Minutes::query()->findOrFail($id), $request->validated()));
    }

    #[AssociationPost('/api/v1/associations/minutes/{id}/approve', 'approveAssociationMinutes', 'Approve the minutes: they get the number of the meetings book and become immutable', response: 'AssociationMinutes')]
    public function approve(Request $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);

        return new AssociationRecordResource($this->minutes->approve(Minutes::query()->findOrFail($id), $this->actorId($request)));
    }

    #[AssociationGet('/api/v1/associations/minutes/{id}/pdf', 'printAssociationMinutes', 'Minutes as PDF', file: true)]
    public function printable(string $id, MeetingDocuments $documents): Response
    {
        $this->allow(AssociationCapability::Read);
        $minutes = Minutes::query()->with('meeting')->findOrFail($id);

        return $this->pdf($documents->minutes($minutes), 'minutes-'.($minutes->number ?? $minutes->id).'.pdf');
    }

    #[AssociationPost('/api/v1/associations/minutes/{id}/distribute', 'distributeAssociationMinutes', 'Send the approved minutes to the participants', 'AssociationSendDocumentRequest', response: 'AssociationDeliveryList')]
    public function distribute(SendDocumentRequest $request, string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        $results = $this->minutes->distribute(
            Minutes::query()->with('meeting')->findOrFail($id),
            DeliveryChannel::from((string) $request->validated('channel')),
            DeliveryMode::from((string) $request->validated('mode')),
            array_values($request->validated('member_ids', [])),
            $this->actorId($request),
        );

        return response()->json(['data' => AssociationRecordResource::collection($results)]);
    }

    #[AssociationGet('/api/v1/associations/minutes/{id}/deliveries', 'listAssociationMinutesDeliveries', 'Distribution of the minutes per recipient', query: ['per_page'], response: 'AssociationDeliveryList')]
    public function deliveries(Request $request, string $id): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Read);
        Minutes::query()->findOrFail($id);

        return AssociationRecordResource::collection(AssociationDelivery::query()->where('subject_id', $id)->orderByDesc('attempted_at')->gridPaginate($this->perPage($request, 100, 500)));
    }
}
