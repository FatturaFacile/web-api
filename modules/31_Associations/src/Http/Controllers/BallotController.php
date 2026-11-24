<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Associations\DTOs\AreaAccess;
use Modules\Associations\Enums\BallotStatus;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Http\Requests\CastVoteRequest;
use Modules\Associations\Http\Requests\StoreBallotRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\Ballot;
use Modules\Associations\Models\BallotVoter;
use Modules\Associations\Models\Meeting;
use Modules\Associations\Models\MeetingParticipant;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\OpenApi\OperationArguments;
use Modules\Associations\Services\BallotService;
use Modules\Associations\Support\AssociationCapability;

/**
 * Ballots of a meeting. The staff prepares, opens and closes them and can record an open vote given in person; the
 * people entitled to vote cast their vote online from the member area, also for those who gave them a proxy.
 */
final class BallotController extends AssociationController
{
    public function __construct(private readonly BallotService $ballots) {}

    #[AssociationPost('/api/v1/associations/meetings/{id}/ballots', 'createAssociationBallot', 'Prepare a ballot, open or secret, with its majority rule', 'AssociationBallotRequest', response: 'AssociationBallot')]
    public function store(StoreBallotRequest $request, string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);

        return (new AssociationRecordResource($this->ballots->create(Meeting::query()->findOrFail($id), $request->validated())))->response()->setStatusCode(201);
    }

    #[AssociationGet('/api/v1/associations/ballots/{id}', 'showAssociationBallot', 'Show a ballot; a secret ballot shows counts and never who chose what', response: 'AssociationBallot')]
    public function show(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Read);
        $ballot = Ballot::query()->findOrFail($id);

        return (new AssociationRecordResource($ballot))->additional(['meta' => [
            'voters' => BallotVoter::query()->where('ballot_id', $ballot->id)->count(),
            'live_results' => $ballot->status === BallotStatus::Closed ? null : ($ballot->is_secret ? null : $this->ballots->tally($ballot)),
        ]])->response();
    }

    #[AssociationPost('/api/v1/associations/ballots/{id}/open', 'openAssociationBallot', 'Open the ballot during a held meeting', response: 'AssociationBallot')]
    public function open(string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);

        return new AssociationRecordResource($this->ballots->open(Ballot::query()->with('meeting')->findOrFail($id)));
    }

    #[AssociationPost('/api/v1/associations/ballots/{id}/close', 'closeAssociationBallot', 'Close the ballot and store the result', response: 'AssociationBallot')]
    public function close(string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);

        return new AssociationRecordResource($this->ballots->close(Ballot::query()->findOrFail($id)));
    }

    #[AssociationPost('/api/v1/associations/ballots/{id}/votes', 'recordAssociationInPersonVote', 'Record an open vote given in person. Secret ballots are voted only by the people themselves', 'AssociationVoteRequest')]
    public function recordVote(CastVoteRequest $request, string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        $ballot = Ballot::query()->findOrFail($id);

        if ($ballot->is_secret) {
            throw new AssociationException(__('association_governance.secret_needs_voter'));
        }

        $participant = MeetingParticipant::query()->where('meeting_id', $ballot->meeting_id)->findOrFail((string) $request->validated('participant_id'));
        $this->ballots->vote($ballot, $participant, $request->validated('on_behalf_of'), (string) $request->validated('choice'), 'in_person');

        return response()->json(['data' => ['recorded' => true]], 201);
    }

    #[AssociationGet('/api/v1/association-area/ballots', 'listAssociationAreaBallots', 'The open ballots the person of the token can vote on, also by proxy', OperationArguments::Area, response: 'AssociationAreaBallotList')]
    public function areaIndex(Request $request): JsonResponse
    {
        $participants = $this->areaParticipants($request);
        $result = [];

        foreach ($participants as $participant) {
            $represented = MeetingParticipant::query()->where('proxy_holder_id', $participant->id)->pluck('id')->all();

            foreach ($this->ballots->openBallotsFor($participant) as $ballot) {
                $done = BallotVoter::query()->where('ballot_id', $ballot->id)->pluck('participant_id')->all();
                $result[] = [
                    'id' => $ballot->id,
                    'meeting_id' => $ballot->meeting_id,
                    'question' => $ballot->question,
                    'options' => $ballot->options,
                    'is_secret' => $ballot->is_secret,
                    'can_vote_self' => $participant->is_eligible_voter && $participant->proxy_holder_id === null && ! in_array($participant->id, $done, true),
                    'can_vote_for' => array_values(array_diff($represented, $done)),
                ];
            }
        }

        return response()->json(['data' => $result]);
    }

    #[AssociationPost('/api/v1/association-area/ballots/{id}/vote', 'castAssociationAreaVote', 'Vote online once, for oneself or for a person represented by proxy', 'AssociationVoteRequest', OperationArguments::Area)]
    public function areaVote(CastVoteRequest $request, string $id): JsonResponse
    {
        $ballot = Ballot::query()->findOrFail($id);
        $participant = $this->areaParticipants($request)->firstWhere('meeting_id', $ballot->meeting_id)
            ?? throw new AssociationException(__('association_governance.not_eligible'));

        $this->ballots->vote($ballot, $participant, $request->validated('on_behalf_of'), (string) $request->validated('choice'), 'online');

        return response()->json(['data' => ['recorded' => true]], 201);
    }

    /**
     * @return Collection<int, MeetingParticipant>
     */
    private function areaParticipants(Request $request): Collection
    {
        /** @var AreaAccess $access */
        $access = $request->attributes->get('areaAccess');

        if ($access->self === null) {
            return collect();
        }

        return MeetingParticipant::query()->where('member_id', $access->self->id)->get();
    }
}
