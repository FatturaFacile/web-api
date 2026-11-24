<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Services\Sales\DocumentNumberingService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Enums\BallotStatus;
use Modules\Associations\Enums\DeliveryChannel;
use Modules\Associations\Enums\DeliveryMode;
use Modules\Associations\Enums\MeetingStatus;
use Modules\Associations\Enums\MinutesStatus;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\AssociationDelivery;
use Modules\Associations\Models\Ballot;
use Modules\Associations\Models\Meeting;
use Modules\Associations\Models\MeetingParticipant;
use Modules\Associations\Models\Minutes;

/**
 * Minutes generated from a meeting, with attendance, agenda, decisions and ballot results already written, edited by the
 * secretary and then approved. Approval gives the number of the meetings book and a hash of the text; from then on the
 * database refuses any change.
 */
final class MinutesService
{
    private const string Sequence = 'association_minutes';

    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly TenantContext $tenantContext,
        private readonly MeetingService $meetings,
        private readonly MeetingDocuments $documents,
        private readonly NoticeDeliveryService $deliveries,
    ) {}

    public function draft(Meeting $meeting): Minutes
    {
        if (! in_array($meeting->status, [MeetingStatus::Held, MeetingStatus::Closed], true)) {
            throw AssociationException::conflict(__('association_governance.meeting_not_held'));
        }

        if (Minutes::query()->where('meeting_id', $meeting->id)->exists()) {
            throw AssociationException::conflict(__('association_governance.minutes_exist'));
        }

        return Minutes::create([
            'meeting_id' => $meeting->id,
            'status' => MinutesStatus::Draft,
            'title' => (string) __('association_governance.minutes_title', ['body' => $meeting->bodyLabel(), 'date' => $meeting->held_at?->format('d/m/Y') ?? '']),
            'body' => $this->prefill($meeting),
        ]);
    }

    /**
     * @param  array{title?: string, body?: string}  $data
     */
    public function update(Minutes $minutes, array $data): Minutes
    {
        $this->assertDraft($minutes);
        $minutes->update($data);

        return $minutes;
    }

    public function approve(Minutes $minutes, ?string $userId): Minutes
    {
        $this->assertDraft($minutes);

        return DB::transaction(function () use ($minutes, $userId): Minutes {
            $year = (int) now()->year;
            $sequence = $this->numbering->next(self::Sequence, $year, (string) $this->tenantContext->getTenantId());

            $minutes->update([
                'status' => MinutesStatus::Approved,
                'number' => sprintf('VRB%d-%04d', $sequence['fiscal_year'], $sequence['sequential_number']),
                'fiscal_year' => $sequence['fiscal_year'],
                'sequential_number' => $sequence['sequential_number'],
                'content_hash' => hash('sha256', $minutes->title."\n".$minutes->body),
                'approved_at' => now(),
                'approved_by' => $userId,
            ]);

            return $minutes;
        });
    }

    /**
     * Sends the approved minutes to the participants of the meeting.
     *
     * @param  list<string>  $selectedMemberIds
     * @return list<AssociationDelivery>
     */
    public function distribute(Minutes $minutes, DeliveryChannel $channel, DeliveryMode $mode, array $selectedMemberIds, ?string $userId): array
    {
        if ($minutes->status !== MinutesStatus::Approved) {
            throw AssociationException::conflict(__('association_governance.minutes_not_approved'));
        }

        return $this->deliveries->deliver(
            AssociationDelivery::MinutesCopy,
            $minutes->id,
            $this->meetings->participantMembers($minutes->meeting),
            $channel,
            $mode,
            $selectedMemberIds,
            $minutes->title,
            (string) __('association_governance.minutes_mail_line', ['title' => $minutes->title]),
            'minutes.pdf',
            $this->documents->minutes($minutes),
            $userId,
        );
    }

    private function prefill(Meeting $meeting): string
    {
        $participants = MeetingParticipant::query()->with(['member.contact', 'proxyHolder.member.contact'])->where('meeting_id', $meeting->id)->get();
        $quorum = $this->meetings->quorum($meeting);
        $lines = [
            (string) __('association_governance.minutes_opening', [
                'body' => $meeting->bodyLabel(),
                'date' => $meeting->held_at?->format('d/m/Y H:i') ?? '',
                'location' => $meeting->location ?? $meeting->online_url ?? '-',
                'call' => __('association_governance.call.'.($meeting->held_on_call ?? 'first')),
            ]),
            '',
            (string) __('association_governance.present'),
        ];

        foreach ($participants->filter(static fn (MeetingParticipant $participant): bool => $participant->isAttending()) as $participant) {
            $lines[] = '- '.$participant->member->displayName().' ('.__('association_governance.attendance.'.$participant->attendance->value).')';
        }

        foreach ($participants->filter(static fn (MeetingParticipant $participant): bool => $participant->proxy_holder_id !== null) as $participant) {
            $lines[] = '- '.__('association_governance.represented_by', ['name' => $participant->member->displayName(), 'holder' => $participant->proxyHolder?->member?->displayName() ?? '']);
        }

        $lines[] = '';
        $lines[] = (string) __('association_governance.quorum_line', ['counted' => $quorum['counted'], 'eligible' => $quorum['eligible']]);
        $lines[] = '';
        $lines[] = (string) __('association_governance.agenda');

        $ballots = Ballot::query()->where('meeting_id', $meeting->id)->where('status', BallotStatus::Closed)->get();

        foreach ($meeting->agendaItems()->get() as $item) {
            $lines[] = '';
            $lines[] = "{$item->position}. {$item->title}";

            if ($item->description) {
                $lines[] = (string) $item->description;
            }

            if ($item->decision !== null) {
                $lines[] = (string) __('association_governance.decision_line', ['decision' => __('association_governance.decision.'.$item->decision)]);
            }

            foreach ($ballots->where('agenda_item_id', $item->id) as $ballot) {
                $lines[] = $this->ballotLine($ballot);
            }
        }

        foreach ($ballots->whereNull('agenda_item_id') as $ballot) {
            $lines[] = '';
            $lines[] = $this->ballotLine($ballot);
        }

        return implode("\n", $lines);
    }

    private function ballotLine(Ballot $ballot): string
    {
        $counts = collect($ballot->results['counts'] ?? [])->map(static fn (int $count, string $option): string => "{$option}: {$count}")->implode(', ');

        return (string) __('association_governance.ballot_line', ['question' => $ballot->question, 'counts' => $counts, 'outcome' => $ballot->results['outcome'] ?? '']);
    }

    private function assertDraft(Minutes $minutes): void
    {
        if ($minutes->status !== MinutesStatus::Draft) {
            throw AssociationException::conflict(__('association_governance.minutes_locked'));
        }
    }
}
