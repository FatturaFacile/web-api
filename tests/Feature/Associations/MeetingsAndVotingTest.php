<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\AssociativeYear;
use Modules\Associations\Models\BallotVote;
use Modules\Associations\Models\BallotVoter;
use Modules\Associations\Models\Meeting;
use Modules\Associations\Models\MeetingParticipant;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\MembershipType;
use Modules\Associations\Services\MembershipService;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    Mail::fake();
    $this->withoutMiddleware(ThrottleRequests::class);

    $year = AssociativeYear::factory()->create();
    $type = MembershipType::factory()->create();
    $service = app(MembershipService::class);
    $this->members = collect(range(1, 4))->map(function () use ($service, $year, $type): Member {
        $member = Member::factory()->create();
        $service->activate($service->request($member, $year, $type));

        return $member;
    });
    $this->outsider = Member::factory()->create();
    AssociationSetting::current()->update(['max_proxies_per_holder' => 1]);
});

afterEach(fn () => associationsTearDown());

/**
 * A convened assembly of the four active members with a 50% quorum on first call.
 */
function governanceConvenedMeeting(object $test, array $attributes = []): Meeting
{
    actAs($test, 'secretary');
    $id = $test->postJson('/api/v1/associations/meetings', [
        'body' => 'assembly',
        'title' => 'Annual assembly',
        'scheduled_at' => now()->addDays(10)->toIso8601String(),
        'first_call_quorum_percent' => 50,
        'proxies_allowed' => true,
        ...$attributes,
    ])->assertCreated()->json('data.id');

    $test->postJson("/api/v1/associations/meetings/{$id}/agenda-items", ['title' => 'Approval of the statements'])->assertCreated();
    $test->postJson("/api/v1/associations/meetings/{$id}/participants", ['source' => 'members'])->assertOk()->assertJsonPath('data.added', 4);
    $test->postJson("/api/v1/associations/meetings/{$id}/notices", ['channel' => 'email', 'mode' => 'all'])->assertOk();

    return Meeting::query()->findOrFail($id);
}

function governanceParticipant(Meeting $meeting, Member $member): MeetingParticipant
{
    return MeetingParticipant::query()->where('meeting_id', $meeting->id)->where('member_id', $member->id)->firstOrFail();
}

function governanceHeldMeeting(object $test): Meeting
{
    $meeting = governanceConvenedMeeting($test);
    $entries = $test->members->take(3)->map(fn (Member $member): array => ['participant_id' => governanceParticipant($meeting, $member)->id, 'attendance' => 'present'])->all();
    $test->postJson("/api/v1/associations/meetings/{$meeting->id}/attendance", ['entries' => $entries])->assertOk();
    $test->postJson("/api/v1/associations/meetings/{$meeting->id}/hold", ['call' => 'first'])->assertOk();

    return $meeting->refresh();
}

test('participants come from the registry: only members with a membership in force are called', function (): void {
    $meeting = governanceConvenedMeeting($this);

    expect(MeetingParticipant::query()->where('meeting_id', $meeting->id)->pluck('member_id')->all())
        ->toEqualCanonicalizing($this->members->pluck('id')->all())
        ->and($meeting->status->value)->toBe('convened');
});

test('every notice is tracked per recipient, and a selective send reaches only those not reached yet', function (): void {
    $meeting = governanceConvenedMeeting($this);

    $deliveries = $this->getJson("/api/v1/associations/meetings/{$meeting->id}/deliveries")->assertOk()->json('data');
    expect($deliveries)->toHaveCount(4)
        ->and(collect($deliveries)->pluck('status')->unique()->all())->toBe(['sent'])
        ->and($deliveries[0])->toHaveKeys(['channel', 'attempted_at', 'member_id']);

    $this->postJson("/api/v1/associations/meetings/{$meeting->id}/notices", ['channel' => 'email', 'mode' => 'unreached'])->assertOk()->assertJsonCount(0, 'data');

    $late = $this->outsider;
    $this->postJson("/api/v1/associations/meetings/{$meeting->id}/participants", ['source' => 'selected', 'member_ids' => [$late->id]])->assertOk();
    $this->postJson("/api/v1/associations/meetings/{$meeting->id}/notices", ['channel' => 'email', 'mode' => 'unreached'])->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.member_id', $late->id);
});

test('the printable letter is a PDF and counts as delivered on paper', function (): void {
    $meeting = governanceConvenedMeeting($this);

    $response = $this->get("/api/v1/associations/meetings/{$meeting->id}/letter")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(DB::table('assoc_deliveries')->where('subject_id', $meeting->id)->where('channel', 'letter')->count())->toBe(4);
});

test('the meeting cannot be held without the quorum of the call', function (): void {
    $meeting = governanceConvenedMeeting($this);
    $this->postJson("/api/v1/associations/meetings/{$meeting->id}/attendance", ['entries' => [
        ['participant_id' => governanceParticipant($meeting, $this->members[0])->id, 'attendance' => 'present'],
    ]])->assertOk()->assertJsonPath('data.counted', 1)->assertJsonPath('data.required', 2);

    $this->postJson("/api/v1/associations/meetings/{$meeting->id}/hold", ['call' => 'first'])->assertUnprocessable();

    $this->postJson("/api/v1/associations/meetings/{$meeting->id}/proxies", [
        'grantor_participant_id' => governanceParticipant($meeting, $this->members[1])->id,
        'holder_participant_id' => governanceParticipant($meeting, $this->members[0])->id,
    ])->assertOk();

    $this->getJson("/api/v1/associations/meetings/{$meeting->id}/quorum")->assertJsonPath('data.represented', 1)->assertJsonPath('data.reached', true);
    $this->postJson("/api/v1/associations/meetings/{$meeting->id}/hold", ['call' => 'first'])->assertOk()->assertJsonPath('data.status', 'held');
});

test('a holder cannot carry more proxies than the limit of the company', function (): void {
    $meeting = governanceConvenedMeeting($this);
    $holder = governanceParticipant($meeting, $this->members[0])->id;

    $this->postJson("/api/v1/associations/meetings/{$meeting->id}/proxies", ['grantor_participant_id' => governanceParticipant($meeting, $this->members[1])->id, 'holder_participant_id' => $holder])->assertOk();
    $this->postJson("/api/v1/associations/meetings/{$meeting->id}/proxies", ['grantor_participant_id' => governanceParticipant($meeting, $this->members[2])->id, 'holder_participant_id' => $holder])->assertConflict();

    AssociationSetting::current()->update(['max_proxies_per_holder' => null]);
    $this->postJson("/api/v1/associations/meetings/{$meeting->id}/proxies", ['grantor_participant_id' => governanceParticipant($meeting, $this->members[3])->id, 'holder_participant_id' => governanceParticipant($meeting, $this->members[2])->id])->assertUnprocessable();
});

test('each eligible person votes once online, and a second vote is refused', function (): void {
    $meeting = governanceHeldMeeting($this);
    $ballotId = $this->postJson("/api/v1/associations/meetings/{$meeting->id}/ballots", ['question' => 'Approve the statements?'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/associations/ballots/{$ballotId}/open")->assertOk();

    $token = areaToken($this->members[0]->contact);
    $this->getJson('/api/v1/association-area/ballots', areaHeaders($token))->assertOk()->assertJsonPath('data.0.can_vote_self', true);
    $this->postJson("/api/v1/association-area/ballots/{$ballotId}/vote", ['choice' => 'yes'], areaHeaders($token))->assertCreated();
    $this->postJson("/api/v1/association-area/ballots/{$ballotId}/vote", ['choice' => 'no'], areaHeaders($token))->assertConflict();
    $this->postJson("/api/v1/association-area/ballots/{$ballotId}/vote", ['choice' => 'maybe'], areaHeaders(areaToken($this->members[1]->contact)))->assertUnprocessable();

    $outsiderToken = areaToken($this->outsider->contact);
    $this->postJson("/api/v1/association-area/ballots/{$ballotId}/vote", ['choice' => 'yes'], areaHeaders($outsiderToken))->assertUnprocessable();

    actAs($this, 'secretary');
    $this->postJson("/api/v1/associations/ballots/{$ballotId}/close")->assertOk()
        ->assertJsonPath('data.results.counts.yes', 1)
        ->assertJsonPath('data.results.voters', 1)
        ->assertJsonPath('data.results.by_person.0.participant_id', governanceParticipant($meeting, $this->members[0])->id);
});

test('a holder votes for the person it represents, once', function (): void {
    $meeting = governanceConvenedMeeting($this);
    $holder = governanceParticipant($meeting, $this->members[0]);
    $grantor = governanceParticipant($meeting, $this->members[1]);
    $this->postJson("/api/v1/associations/meetings/{$meeting->id}/attendance", ['entries' => [['participant_id' => $holder->id, 'attendance' => 'online']]])->assertOk();
    $this->postJson("/api/v1/associations/meetings/{$meeting->id}/proxies", ['grantor_participant_id' => $grantor->id, 'holder_participant_id' => $holder->id])->assertOk();
    $this->postJson("/api/v1/associations/meetings/{$meeting->id}/hold", ['call' => 'first'])->assertOk();
    $ballotId = $this->postJson("/api/v1/associations/meetings/{$meeting->id}/ballots", ['question' => 'Budget?'])->json('data.id');
    $this->postJson("/api/v1/associations/ballots/{$ballotId}/open")->assertOk();

    $token = areaToken($this->members[0]->contact);
    $this->getJson('/api/v1/association-area/ballots', areaHeaders($token))->assertJsonPath('data.0.can_vote_for', [$grantor->id]);
    $this->postJson("/api/v1/association-area/ballots/{$ballotId}/vote", ['choice' => 'no', 'on_behalf_of' => $grantor->id], areaHeaders($token))->assertCreated();
    $this->postJson("/api/v1/association-area/ballots/{$ballotId}/vote", ['choice' => 'no', 'on_behalf_of' => $grantor->id], areaHeaders($token))->assertConflict();
    $this->postJson("/api/v1/association-area/ballots/{$ballotId}/vote", ['choice' => 'no'], areaHeaders(areaToken($this->members[1]->contact)))->assertUnprocessable();
    $this->postJson("/api/v1/association-area/ballots/{$ballotId}/vote", ['choice' => 'yes'], areaHeaders($token))->assertCreated();

    expect(BallotVoter::query()->where('ballot_id', $ballotId)->count())->toBe(2);
});

test('a secret ballot keeps no link between the voter and the choice', function (): void {
    $meeting = governanceHeldMeeting($this);
    $ballotId = $this->postJson("/api/v1/associations/meetings/{$meeting->id}/ballots", ['question' => 'Elect the board', 'is_secret' => true, 'options' => ['alice', 'bob']])->json('data.id');
    $this->postJson("/api/v1/associations/ballots/{$ballotId}/open")->assertOk();
    $this->postJson("/api/v1/associations/ballots/{$ballotId}/votes", ['participant_id' => governanceParticipant($meeting, $this->members[0])->id, 'choice' => 'alice'])->assertUnprocessable();

    foreach ([[0, 'alice'], [1, 'bob'], [2, 'alice']] as [$index, $choice]) {
        $this->postJson("/api/v1/association-area/ballots/{$ballotId}/vote", ['choice' => $choice], areaHeaders(areaToken($this->members[$index]->contact)))->assertCreated();
    }

    expect(Schema::getColumnListing('assoc_ballot_votes'))->toEqualCanonicalizing(['id', 'tenant_id', 'ballot_id', 'choice', 'participant_id'])
        ->and(BallotVote::query()->where('ballot_id', $ballotId)->whereNotNull('participant_id')->count())->toBe(0)
        ->and(BallotVoter::query()->where('ballot_id', $ballotId)->whereNotNull('voted_at')->count())->toBe(0)
        ->and(BallotVoter::query()->where('ballot_id', $ballotId)->whereNotNull('cast_by_participant_id')->count())->toBe(0);

    $ids = BallotVote::query()->where('ballot_id', $ballotId)->pluck('id')->all();
    expect(collect($ids)->every(static fn (string $id): bool => $id[14] === '4'))->toBeTrue();

    actAs($this, 'secretary');
    $this->getJson("/api/v1/associations/ballots/{$ballotId}")->assertOk()->assertJsonPath('meta.live_results', null);
    $results = $this->postJson("/api/v1/associations/ballots/{$ballotId}/close")->assertOk()->json('data.results');

    expect($results['counts'])->toBe(['alice' => 2, 'bob' => 1])->and($results)->not->toHaveKey('by_person');
});

test('votes are append-only in the database', function (): void {
    $meeting = governanceHeldMeeting($this);
    $ballotId = $this->postJson("/api/v1/associations/meetings/{$meeting->id}/ballots", ['question' => 'Q'])->json('data.id');
    $this->postJson("/api/v1/associations/ballots/{$ballotId}/open")->assertOk();
    $this->postJson("/api/v1/associations/ballots/{$ballotId}/votes", ['participant_id' => governanceParticipant($meeting, $this->members[0])->id, 'choice' => 'yes'])->assertCreated();

    expect(fn () => DB::table('assoc_ballot_votes')->where('ballot_id', $ballotId)->update(['choice' => 'no']))->toThrow(Exception::class);
});

test('an accountant reads meetings but cannot convene or change them', function (): void {
    $meeting = Meeting::factory()->create();
    actAs($this, 'accountant');

    $this->getJson('/api/v1/associations/meetings')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/associations/meetings', ['body' => 'board', 'title' => 'X'])->assertForbidden();
    $this->postJson("/api/v1/associations/meetings/{$meeting->id}/agenda-items", ['title' => 'Y'])->assertForbidden();
});

test('meetings of another company are invisible', function (): void {
    $foreign = inTenant($this->otherTenant, fn (): Meeting => Meeting::factory()->create());
    actAs($this, 'admin');

    $this->getJson("/api/v1/associations/meetings/{$foreign->id}")->assertNotFound();
    $this->getJson('/api/v1/associations/meetings')->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/associations/meetings/{$foreign->id}/participants", ['source' => 'members'])->assertNotFound();
});
