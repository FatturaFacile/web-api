<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Associations\Http\Requests\CheckInRequest;
use Modules\Associations\Http\Requests\MemberReferenceRequest;
use Modules\Associations\Http\Requests\StoreTicketTypeRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\EventRegistration;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\TicketType;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\Services\EventService;

/**
 * Tickets and registrations of events.
 */
final class EventController extends AssociationController
{
    public function __construct(private readonly EventService $events) {}

    #[AssociationPost('/api/v1/associations/courses/{id}/ticket-types', 'addAssociationTicketType', 'Add a ticket type to an event', 'AssociationTicketTypeRequest')]
    public function storeTicketType(StoreTicketTypeRequest $request, string $id): AssociationRecordResource
    {
        $event = Course::query()->findOrFail($id);
        $this->authorize('update', $event);

        return new AssociationRecordResource(TicketType::create([...$request->validated(), 'course_id' => $event->id]));
    }

    #[AssociationGet('/api/v1/associations/courses/{id}/registrations', 'listAssociationEventRegistrations', 'List the registrations of an event')]
    public function registrations(string $id): AnonymousResourceCollection
    {
        $event = Course::query()->findOrFail($id);
        $this->authorize('view', $event);

        return AssociationRecordResource::collection(EventRegistration::query()->with('ticketType')->where('course_id', $event->id)->orderBy('status')->orderBy('waitlist_position')->get());
    }

    #[AssociationPost('/api/v1/associations/ticket-types/{id}/registrations', 'registerAssociationEventTicket', 'Register a person for an event', 'AssociationMemberReferenceRequest')]
    public function register(MemberReferenceRequest $request, string $id): AssociationRecordResource
    {
        $ticketType = TicketType::query()->findOrFail($id);
        $this->authorize('update', $ticketType);

        return new AssociationRecordResource($this->events->register($ticketType, Member::query()->findOrFail($request->validated('member_id'))));
    }

    #[AssociationPost('/api/v1/associations/event-registrations/{id}/cancel', 'cancelAssociationEventRegistration', 'Cancel a registration; the waiting list moves up')]
    public function cancel(string $id): AssociationRecordResource
    {
        $registration = EventRegistration::query()->findOrFail($id);
        $this->authorize('create', EventRegistration::class);

        return new AssociationRecordResource($this->events->cancel($registration));
    }

    #[AssociationPost('/api/v1/associations/courses/{id}/check-in', 'checkInAssociationEventTicket', 'Check a ticket in at the entrance of an event', 'AssociationCheckInRequest')]
    public function checkIn(CheckInRequest $request, string $id): AssociationRecordResource
    {
        $event = Course::query()->findOrFail($id);
        $this->authorize('update', $event);

        return new AssociationRecordResource($this->events->checkIn($event, (string) $request->validated('ticket_code')));
    }
}
