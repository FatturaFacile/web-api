<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Associations\Enums\DeliveryChannel;
use Modules\Associations\Enums\DeliveryMode;
use Modules\Associations\Enums\MeetingStatus;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Http\Requests\AddParticipantsRequest;
use Modules\Associations\Http\Requests\DecideAgendaItemRequest;
use Modules\Associations\Http\Requests\GrantProxyRequest;
use Modules\Associations\Http\Requests\HoldMeetingRequest;
use Modules\Associations\Http\Requests\MeetingAttendanceRequest;
use Modules\Associations\Http\Requests\SaveMeetingRequest;
use Modules\Associations\Http\Requests\SendDocumentRequest;
use Modules\Associations\Http\Requests\StoreAgendaItemRequest;
use Modules\Associations\Http\Requests\StoreFileRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\AssociationDelivery;
use Modules\Associations\Models\Meeting;
use Modules\Associations\Models\MeetingAgendaItem;
use Modules\Associations\Models\MeetingAttachment;
use Modules\Associations\Models\MeetingParticipant;
use Modules\Associations\OpenApi\AssociationDelete;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\OpenApi\AssociationPut;
use Modules\Associations\Services\EnrolmentService;
use Modules\Associations\Services\MeetingBoardDecisionGateway;
use Modules\Associations\Services\MeetingService;
use Modules\Associations\Services\PrivateFiles;
use Modules\Associations\Support\AssociationCapability;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Meetings of the assembly, of the board and of the other bodies: notice, agenda, participants, delivery tracking,
 * attendance, proxies and quorum.
 */
final class MeetingController extends AssociationController
{
    public function __construct(private readonly MeetingService $meetings) {}

    #[AssociationGet('/api/v1/associations/meetings', 'listAssociationMeetings', 'List the meetings, filtered by body and status', query: ['body', 'status', 'per_page'], response: 'AssociationMeeting')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Read);

        return AssociationRecordResource::collection(Meeting::query()
            ->when($request->filled('body'), static fn ($query) => $query->where('body', $request->string('body')->toString()))
            ->when($request->filled('status'), static fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByDesc('scheduled_at')
            ->paginate($this->perPage($request)));
    }

    #[AssociationPost('/api/v1/associations/meetings', 'createAssociationMeeting', 'Prepare a meeting of a body, with its quorum rules', 'AssociationMeetingRequest', response: 'AssociationMeeting')]
    public function store(SaveMeetingRequest $request): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);

        return (new AssociationRecordResource($this->meetings->create($request->validated())))->response()->setStatusCode(201);
    }

    #[AssociationGet('/api/v1/associations/meetings/{id}', 'showAssociationMeeting', 'Show a meeting with agenda, attachments, participants and quorum', response: 'AssociationMeeting')]
    public function show(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Read);
        $meeting = Meeting::query()->with(['agendaItems', 'attachments', 'participants.member.contact:id,first_name,last_name'])->findOrFail($id);

        return (new AssociationRecordResource($meeting))->additional(['meta' => ['quorum' => $this->meetings->quorum($meeting)]])->response();
    }

    #[AssociationPut('/api/v1/associations/meetings/{id}', 'updateAssociationMeeting', 'Update a meeting until it is held', 'AssociationMeetingRequest', response: 'AssociationMeeting')]
    public function update(SaveMeetingRequest $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);

        return new AssociationRecordResource($this->meetings->update(Meeting::query()->findOrFail($id), $request->validated()));
    }

    #[AssociationDelete('/api/v1/associations/meetings/{id}', 'deleteAssociationMeeting', 'Delete a meeting that is still a draft')]
    public function destroy(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        $meeting = Meeting::query()->findOrFail($id);

        if ($meeting->status !== MeetingStatus::Draft || MeetingAgendaItem::query()->where('meeting_id', $id)->whereNotNull('application_id')->exists()) {
            throw AssociationException::conflict(__('association_governance.meeting_locked'));
        }

        $meeting->delete();

        return response()->json(null, 204);
    }

    #[AssociationPost('/api/v1/associations/meetings/{id}/agenda-items', 'addAssociationAgendaItem', 'Add a point to the agenda', 'AssociationAgendaItemRequest', response: 'AssociationRecord')]
    public function addAgendaItem(StoreAgendaItemRequest $request, string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        $item = $this->meetings->addAgendaItem(Meeting::query()->findOrFail($id), $request->validated());

        return (new AssociationRecordResource($item))->response()->setStatusCode(201);
    }

    #[AssociationDelete('/api/v1/associations/agenda-items/{id}', 'deleteAssociationAgendaItem', 'Remove a point of the agenda before the meeting is held')]
    public function removeAgendaItem(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        $this->meetings->removeAgendaItem(MeetingAgendaItem::query()->findOrFail($id));

        return response()->json(null, 204);
    }

    #[AssociationPost('/api/v1/associations/agenda-items/{id}/decision', 'decideAssociationAgendaItem', 'Record the decision of the board on an enrolment application put on the agenda', 'AssociationAgendaDecisionRequest', response: 'AssociationRecord')]
    public function decide(DecideAgendaItemRequest $request, string $id, MeetingBoardDecisionGateway $gateway, EnrolmentService $enrolment): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);
        $item = MeetingAgendaItem::query()->with('meeting')->findOrFail($id);

        return new AssociationRecordResource($gateway->decide($item, $request->boolean('approved'), $this->actorId($request), $request->validated('notes'), $enrolment));
    }

    #[AssociationPost('/api/v1/associations/meetings/{id}/attachments', 'addAssociationMeetingAttachment', 'Attach a document to the notice', 'AssociationFileRequest', multipart: true, response: 'AssociationRecord')]
    public function attach(StoreFileRequest $request, string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        $attachment = $this->meetings->attach(Meeting::query()->findOrFail($id), $request->file('file'));

        return (new AssociationRecordResource($attachment))->response()->setStatusCode(201);
    }

    #[AssociationGet('/api/v1/associations/meeting-attachments/{id}/download', 'downloadAssociationMeetingAttachment', 'Download an attachment of a notice', file: true)]
    public function downloadAttachment(string $id, PrivateFiles $files): StreamedResponse
    {
        $this->allow(AssociationCapability::Read);
        $attachment = MeetingAttachment::query()->findOrFail($id);

        return $files->download($attachment->disk, $attachment->path, $attachment->original_name);
    }

    #[AssociationPost('/api/v1/associations/meetings/{id}/participants', 'addAssociationMeetingParticipants', 'Call people from the registry: active members, office holders, a registry type or a selection', 'AssociationParticipantsRequest')]
    public function addParticipants(AddParticipantsRequest $request, string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);

        return response()->json(['data' => ['added' => $this->meetings->addParticipants(Meeting::query()->findOrFail($id), $request->validated())]]);
    }

    #[AssociationDelete('/api/v1/associations/meeting-participants/{id}', 'deleteAssociationMeetingParticipant', 'Remove a participant before the meeting is held')]
    public function removeParticipant(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        $this->meetings->removeParticipant(MeetingParticipant::query()->with('meeting')->findOrFail($id));

        return response()->json(null, 204);
    }

    #[AssociationPost('/api/v1/associations/meetings/{id}/notices', 'sendAssociationMeetingNotice', 'Send the notice on a channel to everybody, to those not reached yet or to a selection', 'AssociationSendDocumentRequest', response: 'AssociationDeliveryList')]
    public function sendNotice(SendDocumentRequest $request, string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        $results = $this->meetings->sendNotice(
            Meeting::query()->with('agendaItems')->findOrFail($id),
            DeliveryChannel::from((string) $request->validated('channel')),
            DeliveryMode::from((string) $request->validated('mode')),
            array_values($request->validated('member_ids', [])),
            $this->actorId($request),
        );

        return response()->json(['data' => AssociationRecordResource::collection($results)]);
    }

    #[AssociationGet('/api/v1/associations/meetings/{id}/deliveries', 'listAssociationMeetingDeliveries', 'Delivery of the notice per recipient: date, time, channel and outcome', query: ['per_page'], response: 'AssociationDeliveryList')]
    public function deliveries(Request $request, string $id): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Read);
        Meeting::query()->findOrFail($id);

        return AssociationRecordResource::collection(AssociationDelivery::query()->where('subject_id', $id)->orderByDesc('attempted_at')->paginate($this->perPage($request, 100, 500)));
    }

    #[AssociationGet('/api/v1/associations/meetings/{id}/letter', 'printAssociationMeetingLetters', 'Printable letters of the notice, one page per participant; the print is recorded as a delivery', query: ['participant_ids'], file: true)]
    public function letter(Request $request, string $id): Response
    {
        $this->allow(AssociationCapability::Manage);
        $meeting = Meeting::query()->findOrFail($id);
        $ids = $request->filled('participant_ids') ? array_values(array_filter(explode(',', $request->string('participant_ids')->toString()))) : null;

        return $this->pdf($this->meetings->letters($meeting, $ids, $this->actorId($request)), "notice-{$meeting->id}.pdf");
    }

    #[AssociationPost('/api/v1/associations/meetings/{id}/attendance', 'recordAssociationMeetingAttendance', 'Record who is present in person or online and who is absent', 'AssociationMeetingAttendanceRequest')]
    public function attendance(MeetingAttendanceRequest $request, string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        $meeting = Meeting::query()->findOrFail($id);
        $this->meetings->recordAttendance($meeting, $request->validated('entries'));

        return response()->json(['data' => $this->meetings->quorum($meeting)]);
    }

    #[AssociationPost('/api/v1/associations/meetings/{id}/proxies', 'grantAssociationMeetingProxy', 'An absent person gives its proxy to another participant, within the limit set for the company', 'AssociationProxyRequest', response: 'AssociationRecord')]
    public function grantProxy(GrantProxyRequest $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);

        return new AssociationRecordResource($this->meetings->grantProxy(
            Meeting::query()->findOrFail($id),
            (string) $request->validated('grantor_participant_id'),
            (string) $request->validated('holder_participant_id'),
        ));
    }

    #[AssociationDelete('/api/v1/associations/meeting-participants/{id}/proxy', 'revokeAssociationMeetingProxy', 'Withdraw the proxy given by a participant', response: 'AssociationRecord')]
    public function revokeProxy(string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);

        return new AssociationRecordResource($this->meetings->revokeProxy(MeetingParticipant::query()->findOrFail($id)));
    }

    #[AssociationGet('/api/v1/associations/meetings/{id}/quorum', 'showAssociationMeetingQuorum', 'Eligible voters, attendance, proxies and whether the quorum of the call is reached', query: ['call'], response: 'AssociationQuorum')]
    public function quorum(Request $request, string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Read);
        $call = $request->string('call')->toString();

        return response()->json(['data' => $this->meetings->quorum(Meeting::query()->findOrFail($id), in_array($call, ['first', 'second'], true) ? $call : null)]);
    }

    #[AssociationPost('/api/v1/associations/meetings/{id}/hold', 'holdAssociationMeeting', 'Open the meeting on the first or second call; refused without the quorum', 'AssociationHoldMeetingRequest', response: 'AssociationMeeting')]
    public function hold(HoldMeetingRequest $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);

        return new AssociationRecordResource($this->meetings->hold(Meeting::query()->findOrFail($id), (string) $request->validated('call')));
    }

    #[AssociationPost('/api/v1/associations/meetings/{id}/close', 'closeAssociationMeeting', 'Close a held meeting', response: 'AssociationMeeting')]
    public function close(string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);

        return new AssociationRecordResource($this->meetings->close(Meeting::query()->findOrFail($id)));
    }

    #[AssociationPost('/api/v1/associations/meetings/{id}/cancel', 'cancelAssociationMeeting', 'Cancel a meeting that has not been held', response: 'AssociationMeeting')]
    public function cancel(string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);

        return new AssociationRecordResource($this->meetings->cancel(Meeting::query()->findOrFail($id)));
    }
}
