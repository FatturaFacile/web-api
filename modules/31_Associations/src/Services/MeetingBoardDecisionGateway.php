<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Illuminate\Support\Facades\DB;
use Modules\Associations\Contracts\BoardDecisionGateway;
use Modules\Associations\DTOs\BoardDecisionRequest;
use Modules\Associations\Enums\ApplicationStatus;
use Modules\Associations\Enums\MeetingStatus;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\EnrolmentApplication;
use Modules\Associations\Models\MeetingAgendaItem;

/**
 * Puts an application that needs the board on the agenda of the next board meeting, opening a draft meeting when none
 * is planned. The board decides on the point once the meeting is held, and the decision reaches the enrolment through
 * EnrolmentService::applyBoardDecision.
 */
final class MeetingBoardDecisionGateway implements BoardDecisionGateway
{
    public const string ReferencePrefix = 'meeting:';

    public function __construct(private readonly MeetingService $meetings) {}

    public function request(EnrolmentApplication $application): BoardDecisionRequest
    {
        $meeting = $this->meetings->nextBoardMeeting();
        $member = $application->member;

        $this->meetings->addAgendaItem($meeting, [
            'title' => (string) __('association_governance.application_item', ['name' => $member?->displayName() ?? '']),
            'application_id' => $application->id,
        ]);

        return new BoardDecisionRequest(self::ReferencePrefix.$meeting->id);
    }

    /**
     * Records the decision of the board on the point and applies it to the application.
     */
    public function decide(MeetingAgendaItem $item, bool $approved, ?string $userId, ?string $notes, EnrolmentService $enrolment): MeetingAgendaItem
    {
        if ($item->application_id === null) {
            throw new AssociationException(__('association_governance.agenda_item_without_application'));
        }

        if ($item->meeting->status !== MeetingStatus::Held) {
            throw AssociationException::conflict(__('association_governance.meeting_not_held'));
        }

        return DB::transaction(static function () use ($item, $approved, $userId, $notes, $enrolment): MeetingAgendaItem {
            /** @var MeetingAgendaItem $locked */
            $locked = MeetingAgendaItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($locked->decision !== null) {
                throw AssociationException::conflict(__('associations.application_already_decided'));
            }

            $application = EnrolmentApplication::query()->findOrFail($locked->application_id);

            if ($application->status === ApplicationStatus::PendingBoard || $application->status === ApplicationStatus::Submitted) {
                $enrolment->applyBoardDecision($application, $approved, self::ReferencePrefix.$locked->meeting_id, $userId, $notes);
            }

            $locked->update(['decision' => $approved ? 'approved' : 'rejected', 'decision_notes' => $notes]);

            return $locked;
        });
    }
}
