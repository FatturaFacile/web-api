<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Associations\Contracts\BoardDecisionGateway;
use Modules\Associations\Enums\ApplicationStatus;
use Modules\Associations\Enums\MeetingStatus;
use Modules\Associations\Models\AssociativeYear;
use Modules\Associations\Models\EnrolmentApplication;
use Modules\Associations\Models\EnrolmentForm;
use Modules\Associations\Models\Meeting;
use Modules\Associations\Models\MeetingAgendaItem;
use Modules\Associations\Models\MeetingParticipant;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\MembershipType;
use Modules\Associations\Models\Minutes;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    Mail::fake();
    Storage::fake('private');
    config(['filesystems.private_disk' => 'private']);
    $this->withoutMiddleware(ThrottleRequests::class);
});

afterEach(fn () => associationsTearDown());

/**
 * A board meeting with two office holders present, already held.
 */
function minutesHeldBoardMeeting(object $test, ?Meeting $meeting = null): Meeting
{
    actAs($test, 'secretary');
    $meeting ??= Meeting::factory()->board()->create();
    $members = Member::factory()->count(2)->create();
    $test->postJson("/api/v1/associations/meetings/{$meeting->id}/participants", ['source' => 'selected', 'member_ids' => $members->pluck('id')->all()])->assertOk();
    $test->get("/api/v1/associations/meetings/{$meeting->id}/letter")->assertOk();
    $entries = MeetingParticipant::query()->where('meeting_id', $meeting->id)->get()->map(static fn (MeetingParticipant $participant): array => ['participant_id' => $participant->id, 'attendance' => 'present'])->all();
    $test->postJson("/api/v1/associations/meetings/{$meeting->id}/attendance", ['entries' => $entries])->assertOk();
    $test->postJson("/api/v1/associations/meetings/{$meeting->id}/hold", ['call' => 'first'])->assertOk();

    return $meeting->refresh();
}

test('minutes start from the meeting with attendance and agenda written, and stay editable until approved', function (): void {
    $meeting = Meeting::factory()->board()->create();
    MeetingAgendaItem::query()->create(['meeting_id' => $meeting->id, 'position' => 1, 'title' => 'Summer camp budget']);
    $meeting = minutesHeldBoardMeeting($this, $meeting);
    $names = MeetingParticipant::query()->where('meeting_id', $meeting->id)->with('member.contact')->get()->map(static fn (MeetingParticipant $participant): string => $participant->member->displayName());

    $minutes = $this->postJson("/api/v1/associations/meetings/{$meeting->id}/minutes")->assertCreated()->json('data');

    expect($minutes['body'])->toContain('Summer camp budget')->toContain($names[0])->toContain($names[1])
        ->and($minutes['status'])->toBe('draft');

    $this->postJson("/api/v1/associations/meetings/{$meeting->id}/minutes")->assertConflict();
    $this->putJson("/api/v1/associations/minutes/{$minutes['id']}", ['body' => $minutes['body']."\nThe board approves."])->assertOk();

    $approved = $this->postJson("/api/v1/associations/minutes/{$minutes['id']}/approve")->assertOk()->json('data');

    expect($approved['number'])->toStartWith('VRB'.now()->year.'-')
        ->and($approved['content_hash'])->toHaveLength(64);

    $this->putJson("/api/v1/associations/minutes/{$minutes['id']}", ['body' => 'Rewritten'])->assertConflict();
    expect($this->get("/api/v1/associations/minutes/{$minutes['id']}/pdf")->assertOk()->headers->get('Content-Type'))->toBe('application/pdf');
});

test('approved minutes are protected by the database itself', function (): void {
    $meeting = minutesHeldBoardMeeting($this);
    $id = $this->postJson("/api/v1/associations/meetings/{$meeting->id}/minutes")->json('data.id');
    $this->postJson("/api/v1/associations/minutes/{$id}/approve")->assertOk();

    expect(fn () => DB::table('assoc_minutes')->where('id', $id)->update(['title' => 'forged']))->toThrow(Exception::class);
});

test('draft minutes can still be deleted at the database level, approved ones cannot', function (): void {
    $meeting = minutesHeldBoardMeeting($this);
    $id = $this->postJson("/api/v1/associations/meetings/{$meeting->id}/minutes")->json('data.id');

    expect(DB::table('assoc_minutes')->where('id', $id)->update(['title' => 'Draft title']))->toBe(1);
    $this->postJson("/api/v1/associations/minutes/{$id}/approve")->assertOk();

    expect(fn () => DB::table('assoc_minutes')->where('id', $id)->delete())->toThrow(Exception::class);
});

test('approved minutes are distributed with a delivery per participant', function (): void {
    $meeting = minutesHeldBoardMeeting($this);
    $id = $this->postJson("/api/v1/associations/meetings/{$meeting->id}/minutes")->json('data.id');

    $this->postJson("/api/v1/associations/minutes/{$id}/distribute", ['channel' => 'email', 'mode' => 'all'])->assertConflict();
    $this->postJson("/api/v1/associations/minutes/{$id}/approve")->assertOk();
    $this->postJson("/api/v1/associations/minutes/{$id}/distribute", ['channel' => 'email', 'mode' => 'all'])->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/associations/minutes/{$id}/deliveries")->assertOk()->assertJsonCount(2, 'data');
});

test('an application that needs the board goes on the agenda of the next board meeting, and the board decides there', function (): void {
    $form = EnrolmentForm::create([
        'public_token' => Str::random(48),
        'title' => 'Enrolment',
        'year_id' => AssociativeYear::factory()->create()->id,
        'membership_type_id' => MembershipType::factory()->create()->id,
        'approval_mode' => 'board',
    ]);
    app(TenantContext::class)->clear();

    $this->postJson("/api/v1/association-public/enrolment/{$form->public_token}", [
        'first_name' => 'Marta', 'last_name' => 'Verdi', 'email' => 'marta@example.com', 'birth_date' => '1990-01-01',
        'tax_code' => 'VRDMRT90A41H501X', 'privacy_accepted' => true,
    ])->assertCreated();

    actAs($this, 'secretary');
    $application = EnrolmentApplication::query()->firstOrFail();
    $item = MeetingAgendaItem::query()->where('application_id', $application->id)->firstOrFail();
    $meeting = $item->meeting;

    expect($application->status)->toBe(ApplicationStatus::PendingBoard)
        ->and($application->decision_reference)->toBe('meeting:'.$meeting->id)
        ->and($meeting->status)->toBe(MeetingStatus::Draft);

    $this->postJson("/api/v1/associations/agenda-items/{$item->id}/decision", ['approved' => true])->assertConflict();
    $this->deleteJson("/api/v1/associations/agenda-items/{$item->id}")->assertConflict();

    minutesHeldBoardMeeting($this, $meeting);
    $this->postJson("/api/v1/associations/agenda-items/{$item->id}/decision", ['approved' => true, 'notes' => 'Welcome'])->assertOk()->assertJsonPath('data.decision', 'approved');
    $this->postJson("/api/v1/associations/agenda-items/{$item->id}/decision", ['approved' => false])->assertConflict();

    expect($application->refresh()->status)->toBe(ApplicationStatus::Approved)
        ->and($application->decision_reference)->toBe('meeting:'.$meeting->id);

    $minutes = $this->postJson("/api/v1/associations/meetings/{$meeting->id}/minutes")->json('data.body');
    expect($minutes)->toContain('Marta Verdi');
});

test('a second application joins the same pending board meeting', function (): void {
    $gateway = app(BoardDecisionGateway::class);
    $applications = collect(range(1, 2))->map(static fn (): EnrolmentApplication => EnrolmentApplication::query()->create([
        'form_id' => EnrolmentForm::create([
            'public_token' => Str::random(48), 'title' => 'F', 'year_id' => AssociativeYear::factory()->create()->id,
            'membership_type_id' => MembershipType::factory()->create()->id,
        ])->id,
        'member_id' => Member::factory()->create()->id,
        'status' => ApplicationStatus::Submitted,
        'payload' => [],
        'tracking_hash' => hash('sha256', Str::random()),
    ]));

    $references = $applications->map(static fn (EnrolmentApplication $application): ?string => $gateway->request($application)->reference)->unique();

    expect($references)->toHaveCount(1)
        ->and(Minutes::query()->count())->toBe(0)
        ->and(Meeting::query()->count())->toBe(1);
});
