<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Enums\AttendanceStatus;
use Modules\Associations\Enums\DeliveryChannel;
use Modules\Associations\Enums\DeliveryMode;
use Modules\Associations\Enums\MeetingBody;
use Modules\Associations\Enums\MeetingStatus;
use Modules\Associations\Enums\MemberStatus;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\AssociationDelivery;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\Meeting;
use Modules\Associations\Models\MeetingAgendaItem;
use Modules\Associations\Models\MeetingAttachment;
use Modules\Associations\Models\MeetingParticipant;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\MemberSocialRole;
use Modules\Associations\Models\RegistryAssignment;

/**
 * Meetings from the notice to the closing: agenda, participants from the registry, delivery of the notice, attendance,
 * proxies and the quorum. Quorum percentages and the proxy limit come from the statute of each association, so they are
 * data of the meeting and of the settings, never constants of the code.
 */
final class MeetingService
{
    public function __construct(
        private readonly NoticeDeliveryService $deliveries,
        private readonly MeetingDocuments $documents,
        private readonly PrivateFiles $files,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Meeting
    {
        return Meeting::create([...$data, 'status' => MeetingStatus::Draft]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Meeting $meeting, array $data): Meeting
    {
        $this->assertEditable($meeting);
        $meeting->update($data);

        return $meeting;
    }

    /**
     * @param  array{title: string, description?: ?string, application_id?: ?string}  $data
     */
    public function addAgendaItem(Meeting $meeting, array $data): MeetingAgendaItem
    {
        $this->assertEditable($meeting);

        return DB::transaction(static function () use ($meeting, $data): MeetingAgendaItem {
            Meeting::query()->whereKey($meeting->id)->lockForUpdate()->first();
            $position = (int) MeetingAgendaItem::query()->where('meeting_id', $meeting->id)->max('position') + 1;

            return MeetingAgendaItem::create([...$data, 'meeting_id' => $meeting->id, 'position' => $position]);
        });
    }

    public function removeAgendaItem(MeetingAgendaItem $item): void
    {
        $this->assertEditable($item->meeting);

        if ($item->application_id !== null) {
            throw AssociationException::conflict(__('association_governance.agenda_item_has_application'));
        }

        $item->delete();
    }

    public function attach(Meeting $meeting, UploadedFile $file): MeetingAttachment
    {
        $this->assertEditable($meeting);

        return MeetingAttachment::create([...$this->files->store($file, (string) $meeting->tenant_id, "meetings/{$meeting->id}"), 'meeting_id' => $meeting->id]);
    }

    /**
     * Calls people from the registry. For the assembly the members with a membership in force on the day vote, minors
     * excepted; for the board the holders of an office on the day; otherwise the people chosen, or the holders of a
     * registry type.
     *
     * @param  array{source: string, member_ids?: list<string>, registry_type_id?: ?string, social_role_ids?: list<string>, eligible?: bool}  $data
     * @return int people added
     */
    public function addParticipants(Meeting $meeting, array $data): int
    {
        $this->assertEditable($meeting);
        $day = CarbonImmutable::parse($meeting->scheduled_at ?? now())->startOfDay();

        $members = match ($data['source']) {
            'members' => Member::query()->where('status', MemberStatus::Active)->get()->filter(static fn (Member $member): bool => $member->currentMembership($day) !== null),
            'board' => Member::query()->whereIn('id', MemberSocialRole::query()
                ->when(($data['social_role_ids'] ?? []) !== [], static fn ($query) => $query->whereIn('social_role_id', $data['social_role_ids']))
                ->whereDate('valid_from', '<=', $day)
                ->where(static fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day))
                ->select('member_id'))->get(),
            'registry_type' => Member::query()->whereIn('id', RegistryAssignment::query()
                ->where('registry_type_id', $data['registry_type_id'] ?? null)
                ->whereDate('valid_from', '<=', $day)
                ->where(static fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day))
                ->select('member_id'))->get(),
            default => Member::query()->whereIn('id', $data['member_ids'] ?? [])->get(),
        };

        $eligibleByDefault = (bool) ($data['eligible'] ?? true);
        $added = 0;

        foreach ($members as $member) {
            $exists = MeetingParticipant::query()->where('meeting_id', $meeting->id)->where('member_id', $member->id)->exists();

            if ($exists) {
                continue;
            }

            MeetingParticipant::create([
                'meeting_id' => $meeting->id,
                'member_id' => $member->id,
                'is_eligible_voter' => $eligibleByDefault && ! $member->isMinor($day),
                'attendance' => AttendanceStatus::Invited,
            ]);
            $added++;
        }

        return $added;
    }

    public function removeParticipant(MeetingParticipant $participant): void
    {
        $this->assertEditable($participant->meeting);

        if (MeetingParticipant::query()->where('proxy_holder_id', $participant->id)->exists()) {
            throw AssociationException::conflict(__('association_governance.participant_holds_proxies'));
        }

        $participant->delete();
    }

    /**
     * Sends the notice with the agenda. The first send convenes the meeting.
     *
     * @param  list<string>  $selectedMemberIds
     * @return list<AssociationDelivery>
     */
    public function sendNotice(Meeting $meeting, DeliveryChannel $channel, DeliveryMode $mode, array $selectedMemberIds, ?string $userId): array
    {
        $this->assertEditable($meeting);
        $members = $this->participantMembers($meeting);

        if ($members->isEmpty()) {
            throw new AssociationException(__('association_governance.no_participants'));
        }

        $results = $this->deliveries->deliver(
            AssociationDelivery::MeetingNotice,
            $meeting->id,
            $members,
            $channel,
            $mode,
            $selectedMemberIds,
            (string) __('association_governance.notice_subject', ['body' => $meeting->bodyLabel(), 'title' => $meeting->title]),
            $this->documents->noticeText($meeting),
            'notice.pdf',
            $this->documents->letters($meeting, null),
            $userId,
        );

        $this->markConvened($meeting);

        return $results;
    }

    /**
     * The printable letters, one page per participant, recorded as delivered on paper.
     *
     * @param  list<string>|null  $participantIds  null for everybody
     */
    public function letters(Meeting $meeting, ?array $participantIds, ?string $userId): string
    {
        $members = $this->participantMembers($meeting, $participantIds);
        $pdf = $this->documents->letters($meeting, $participantIds);
        $this->deliveries->recordLetters(AssociationDelivery::MeetingNotice, $meeting->id, $members, $userId);

        if ($meeting->status === MeetingStatus::Draft && $members->isNotEmpty()) {
            $this->markConvened($meeting);
        }

        return $pdf;
    }

    /**
     * @param  list<array{participant_id: string, attendance: string}>  $entries
     */
    public function recordAttendance(Meeting $meeting, array $entries): void
    {
        if (! in_array($meeting->status, [MeetingStatus::Convened, MeetingStatus::Held], true)) {
            throw AssociationException::conflict(__('association_governance.meeting_not_convened'));
        }

        DB::transaction(static function () use ($meeting, $entries): void {
            foreach ($entries as $entry) {
                $participant = MeetingParticipant::query()->where('meeting_id', $meeting->id)->findOrFail($entry['participant_id']);
                $attendance = AttendanceStatus::from($entry['attendance']);
                $attending = in_array($attendance, [AttendanceStatus::Present, AttendanceStatus::Online], true);

                $participant->update([
                    'attendance' => $attendance,
                    'attendance_recorded_at' => now(),
                    'proxy_holder_id' => $attending ? null : $participant->proxy_holder_id,
                ]);
            }
        });
    }

    /**
     * An absent person entitled to vote gives a proxy to another one. The proxies a holder can carry are capped by the
     * limit of the settings; with no limit configured proxies are refused.
     */
    public function grantProxy(Meeting $meeting, string $grantorId, string $holderId): MeetingParticipant
    {
        if (! $meeting->proxies_allowed) {
            throw new AssociationException(__('association_governance.proxies_not_allowed'));
        }

        $maximum = AssociationSetting::current()->max_proxies_per_holder;

        if ($maximum === null || (int) $maximum < 1) {
            throw new AssociationException(__('association_governance.proxy_limit_not_configured'));
        }

        return DB::transaction(static function () use ($meeting, $grantorId, $holderId, $maximum): MeetingParticipant {
            Meeting::query()->whereKey($meeting->id)->lockForUpdate()->first();
            $grantor = MeetingParticipant::query()->where('meeting_id', $meeting->id)->findOrFail($grantorId);
            $holder = MeetingParticipant::query()->where('meeting_id', $meeting->id)->findOrFail($holderId);

            if ($grantor->id === $holder->id || ! $grantor->is_eligible_voter || ! $holder->is_eligible_voter) {
                throw new AssociationException(__('association_governance.proxy_invalid'));
            }

            if ($grantor->isAttending() || $holder->proxy_holder_id !== null || MeetingParticipant::query()->where('proxy_holder_id', $grantor->id)->exists()) {
                throw AssociationException::conflict(__('association_governance.proxy_chain'));
            }

            $carried = MeetingParticipant::query()->where('proxy_holder_id', $holder->id)->whereKeyNot($grantor->id)->count();

            if ($carried >= (int) $maximum) {
                throw AssociationException::conflict(__('association_governance.proxy_limit_reached', ['maximum' => $maximum]));
            }

            $grantor->update(['proxy_holder_id' => $holder->id, 'attendance' => AttendanceStatus::Absent]);

            return $grantor;
        });
    }

    public function revokeProxy(MeetingParticipant $grantor): MeetingParticipant
    {
        $grantor->update(['proxy_holder_id' => null]);

        return $grantor;
    }

    /**
     * Eligible voters, those present in person or online, those represented by proxy, and whether the quorum of the
     * call is reached. With no percentage configured for the call the quorum is reported as not configured.
     *
     * @return array{call: string, eligible: int, attending: int, represented: int, counted: int, required_percent: ?string, required: ?int, configured: bool, reached: bool}
     */
    public function quorum(Meeting $meeting, ?string $call = null): array
    {
        $participants = MeetingParticipant::query()->where('meeting_id', $meeting->id)->where('is_eligible_voter', true)->get();
        $eligible = $participants->count();
        $attendingIds = $participants->filter(static fn (MeetingParticipant $participant): bool => $participant->isAttending())->pluck('id')->all();
        $represented = $participants->filter(static fn (MeetingParticipant $participant): bool => $participant->proxy_holder_id !== null && in_array($participant->proxy_holder_id, $attendingIds, true))->count();
        $counted = count($attendingIds) + $represented;

        $call ??= $meeting->held_on_call ?? 'first';
        $percent = $call === 'second' ? $meeting->second_call_quorum_percent : $meeting->first_call_quorum_percent;
        $required = $percent === null ? null : (int) ceil((float) bcdiv(bcmul((string) $eligible, (string) $percent, 4), '100', 4));

        return [
            'call' => $call,
            'eligible' => $eligible,
            'attending' => count($attendingIds),
            'represented' => $represented,
            'counted' => $counted,
            'required_percent' => $percent,
            'required' => $required,
            'configured' => $percent !== null,
            'reached' => $required === null ? $counted > 0 : $counted >= $required && $counted > 0,
        ];
    }

    /**
     * Opens the meeting on the given call. It is refused when the quorum of the call is not reached.
     */
    public function hold(Meeting $meeting, string $call): Meeting
    {
        if ($meeting->status !== MeetingStatus::Convened) {
            throw AssociationException::conflict(__('association_governance.meeting_not_convened'));
        }

        $quorum = $this->quorum($meeting, $call);

        if (! $quorum['reached']) {
            throw new AssociationException(__('association_governance.quorum_not_reached', ['counted' => $quorum['counted'], 'required' => $quorum['required'] ?? 1]));
        }

        $meeting->update(['status' => MeetingStatus::Held, 'held_at' => now(), 'held_on_call' => $call]);

        return $meeting;
    }

    public function close(Meeting $meeting): Meeting
    {
        if ($meeting->status !== MeetingStatus::Held) {
            throw AssociationException::conflict(__('association_governance.meeting_not_held'));
        }

        $meeting->update(['status' => MeetingStatus::Closed, 'closed_at' => now()]);

        return $meeting;
    }

    public function cancel(Meeting $meeting): Meeting
    {
        $this->assertEditable($meeting);
        $meeting->update(['status' => MeetingStatus::Cancelled]);

        return $meeting;
    }

    /**
     * The next board meeting that can still take a point, created as a draft when there is none.
     */
    public function nextBoardMeeting(): Meeting
    {
        $existing = Meeting::query()
            ->where('body', MeetingBody::Board)
            ->whereIn('status', [MeetingStatus::Draft, MeetingStatus::Convened])
            ->where(static fn ($query) => $query->whereNull('scheduled_at')->orWhere('scheduled_at', '>=', now()))
            ->orderByRaw('scheduled_at IS NULL')
            ->orderBy('scheduled_at')
            ->first();

        return $existing ?? $this->create(['body' => MeetingBody::Board, 'title' => (string) __('association_governance.board_queue_title')]);
    }

    /**
     * @param  list<string>|null  $participantIds
     * @return Collection<int, Member>
     */
    public function participantMembers(Meeting $meeting, ?array $participantIds = null): Collection
    {
        return MeetingParticipant::query()
            ->with('member.contact')
            ->where('meeting_id', $meeting->id)
            ->when($participantIds !== null, static fn ($query) => $query->whereIn('id', $participantIds))
            ->get()
            ->map(static fn (MeetingParticipant $participant): Member => $participant->member)
            ->values();
    }

    private function markConvened(Meeting $meeting): void
    {
        if ($meeting->status === MeetingStatus::Draft) {
            $meeting->update(['status' => MeetingStatus::Convened, 'convened_at' => now()]);
        }
    }

    private function assertEditable(Meeting $meeting): void
    {
        if (! $meeting->isEditable()) {
            throw AssociationException::conflict(__('association_governance.meeting_locked'));
        }
    }
}
