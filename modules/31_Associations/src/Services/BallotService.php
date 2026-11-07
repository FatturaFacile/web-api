<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Enums\BallotStatus;
use Modules\Associations\Enums\MajorityRule;
use Modules\Associations\Enums\MeetingStatus;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\Ballot;
use Modules\Associations\Models\BallotVote;
use Modules\Associations\Models\BallotVoter;
use Modules\Associations\Models\Meeting;
use Modules\Associations\Models\MeetingParticipant;

/**
 * Votes during a meeting. Every eligible person votes once, by itself or through the holder of its proxy; the unique key
 * on the voters refuses a second vote even under concurrency. For a secret ballot the vote row keeps the choice and
 * nothing else, and the voter row keeps no time, so nobody can tell who chose what.
 */
final class BallotService
{
    public const array DefaultOptions = ['yes', 'no', 'abstain'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Meeting $meeting, array $data): Ballot
    {
        if (in_array($meeting->status, [MeetingStatus::Closed, MeetingStatus::Cancelled], true)) {
            throw AssociationException::conflict(__('association_governance.meeting_locked'));
        }

        $options = array_values(array_unique($data['options'] ?? self::DefaultOptions));

        if (($data['majority_rule'] ?? 'simple') !== MajorityRule::Qualified->value) {
            $data['qualified_percent'] = null;
        }

        return Ballot::create([...$data, 'options' => $options, 'meeting_id' => $meeting->id, 'status' => BallotStatus::Draft]);
    }

    public function open(Ballot $ballot): Ballot
    {
        if ($ballot->status !== BallotStatus::Draft) {
            throw AssociationException::conflict(__('association_governance.ballot_not_draft'));
        }

        if ($ballot->meeting->status !== MeetingStatus::Held) {
            throw AssociationException::conflict(__('association_governance.meeting_not_held'));
        }

        $ballot->update(['status' => BallotStatus::Open, 'opened_at' => now()]);

        return $ballot;
    }

    /**
     * Casts the vote of a participant, or of the person it represents by proxy.
     */
    public function vote(Ballot $ballot, MeetingParticipant $caster, ?string $onBehalfOfId, string $choice, string $channel): void
    {
        if ($ballot->status !== BallotStatus::Open) {
            throw AssociationException::conflict(__('association_governance.ballot_not_open'));
        }

        if (! in_array($choice, $ballot->options, true)) {
            throw new AssociationException(__('association_governance.choice_invalid'));
        }

        $voter = $this->voterFor($ballot, $caster, $onBehalfOfId);

        try {
            DB::transaction(static function () use ($ballot, $caster, $voter, $choice, $channel): void {
                BallotVoter::query()->create([
                    'ballot_id' => $ballot->id,
                    'participant_id' => $voter->id,
                    'cast_by_participant_id' => $ballot->is_secret || $caster->id === $voter->id ? null : $caster->id,
                    'channel' => $channel,
                    'voted_at' => $ballot->is_secret ? null : now(),
                ]);

                BallotVote::query()->create([
                    'ballot_id' => $ballot->id,
                    'choice' => $choice,
                    'participant_id' => $ballot->is_secret ? null : $voter->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw AssociationException::conflict(__('association_governance.already_voted'));
        }
    }

    /**
     * Closes the ballot and stores the result: counts per option and the outcome under the majority rule.
     */
    public function close(Ballot $ballot): Ballot
    {
        if ($ballot->status !== BallotStatus::Open) {
            throw AssociationException::conflict(__('association_governance.ballot_not_open'));
        }

        $ballot->update(['status' => BallotStatus::Closed, 'closed_at' => now(), 'results' => $this->tally($ballot)]);

        return $ballot;
    }

    /**
     * @return array{eligible: int, voters: int, counts: array<string, int>, outcome: string, by_person?: list<array{participant_id: string, choice: string}>}
     */
    public function tally(Ballot $ballot): array
    {
        $counts = array_fill_keys($ballot->options, 0);

        foreach (BallotVote::query()->where('ballot_id', $ballot->id)->selectRaw('choice, count(*) as total')->groupBy('choice')->get() as $row) {
            $counts[(string) $row->choice] = (int) $row->getAttribute('total');
        }

        $eligible = MeetingParticipant::query()->where('meeting_id', $ballot->meeting_id)->where('is_eligible_voter', true)->count();
        $voters = BallotVoter::query()->where('ballot_id', $ballot->id)->count();
        $yes = $counts['yes'] ?? 0;
        $no = $counts['no'] ?? 0;

        $outcome = match ($ballot->majority_rule) {
            MajorityRule::Simple => $yes > $no ? 'approved' : 'rejected',
            MajorityRule::Absolute => $yes * 2 > $voters ? 'approved' : 'rejected',
            MajorityRule::Qualified => bccomp(bcmul((string) $yes, '100', 4), bcmul((string) $eligible, (string) $ballot->qualified_percent, 4), 4) >= 0 && $yes > 0 ? 'approved' : 'rejected',
        };

        if (! in_array('yes', $ballot->options, true)) {
            $outcome = 'counted';
        }

        $result = ['eligible' => $eligible, 'voters' => $voters, 'counts' => $counts, 'outcome' => $outcome];

        if (! $ballot->is_secret) {
            $result['by_person'] = BallotVote::query()->where('ballot_id', $ballot->id)->get(['participant_id', 'choice'])
                ->map(static fn (BallotVote $vote): array => ['participant_id' => (string) $vote->participant_id, 'choice' => $vote->choice])
                ->sortBy('participant_id')->values()->all();
        }

        return $result;
    }

    /**
     * Ballots a participant can still vote on, for itself or for the people it represents.
     *
     * @return Collection<int, Ballot>
     */
    public function openBallotsFor(MeetingParticipant $participant): Collection
    {
        return Ballot::query()->where('meeting_id', $participant->meeting_id)->where('status', BallotStatus::Open)->get();
    }

    private function voterFor(Ballot $ballot, MeetingParticipant $caster, ?string $onBehalfOfId): MeetingParticipant
    {
        if ($caster->meeting_id !== $ballot->meeting_id) {
            throw new AssociationException(__('association_governance.not_eligible'));
        }

        if ($onBehalfOfId === null || $onBehalfOfId === $caster->id) {
            if (! $caster->is_eligible_voter || $caster->proxy_holder_id !== null) {
                throw new AssociationException(__('association_governance.not_eligible'));
            }

            return $caster;
        }

        $grantor = MeetingParticipant::query()->where('meeting_id', $ballot->meeting_id)->whereKey($onBehalfOfId)->first();

        if ($grantor === null || $grantor->proxy_holder_id !== $caster->id || ! $grantor->is_eligible_voter) {
            throw new AssociationException(__('association_governance.not_eligible'));
        }

        return $grantor;
    }
}
