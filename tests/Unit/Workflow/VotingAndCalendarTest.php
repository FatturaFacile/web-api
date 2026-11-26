<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Workflow\Models\ApprovalDecision;
use Modules\Workflow\Models\BusinessCalendar;
use Modules\Workflow\Models\BusinessCalendarHoliday;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Modules\Workflow\Models\WorkflowNode;
use Modules\Workflow\Services\BusinessCalendarCalculationService;
use Modules\Workflow\Services\HolidayCalendarFactory;
use Modules\Workflow\Services\VotingThresholdEvaluationService;

uses(DatabaseTransactions::class);

/**
 * Steps of a node with the given decisions ('approved', 'rejected' or null for still pending), without touching the database.
 *
 * @param  list<?string>  $decisions
 * @return Collection<int, WorkflowInstanceStep>
 */
function votingSteps(array $decisions): Collection
{
    return collect($decisions)->map(function (?string $decision): WorkflowInstanceStep {
        $step = new WorkflowInstanceStep(['status' => $decision ?? 'pending']);
        $step->setRelation('decisions', new Collection($decision === null ? [] : [new ApprovalDecision(['decision' => $decision])]));

        return $step;
    });
}

/**
 * A node with the voting rule under test.
 *
 * @param  array<string, mixed>  $attributes
 */
function votingNode(string $type, array $attributes = []): WorkflowNode
{
    return new WorkflowNode(['approval_type' => $type, ...$attributes]);
}

test('in all mode every approver must approve and a single rejection closes the branch negatively', function (): void {
    $voting = new VotingThresholdEvaluationService;
    $node = votingNode('and_parallel');

    expect($voting->evaluate($node, votingSteps(['approved', 'approved', null]))['completed'])->toBeFalse()
        ->and($voting->evaluate($node, votingSteps(['approved', 'approved', 'approved'])))->toMatchArray(['completed' => true, 'outcome' => 'approved'])
        ->and($voting->evaluate($node, votingSteps(['approved', 'rejected', null])))->toMatchArray(['completed' => true, 'outcome' => 'rejected']);
});

test('in any mode the first approval completes the node and it is rejected only when everybody rejects', function (): void {
    $voting = new VotingThresholdEvaluationService;
    $node = votingNode('or_parallel');

    expect($voting->evaluate($node, votingSteps(['approved', null, null])))->toMatchArray(['completed' => true, 'outcome' => 'approved'])
        ->and($voting->evaluate($node, votingSteps(['rejected', null, null]))['completed'])->toBeFalse()
        ->and($voting->evaluate($node, votingSteps(['rejected', 'rejected', 'rejected'])))->toMatchArray(['completed' => true, 'outcome' => 'rejected']);
});

test('in quorum mode the node completes when the minimum of approvals and the percentage are reached', function (): void {
    $voting = new VotingThresholdEvaluationService;
    // Three of five (60%) with at least three votes.
    $node = votingNode('supermajority', ['voting_threshold_percentage' => '60', 'minimum_votes_required' => 3]);

    expect($voting->evaluate($node, votingSteps(['approved', 'approved', null, null, null]))['completed'])->toBeFalse()
        ->and($voting->evaluate($node, votingSteps(['approved', 'approved', 'approved', null, null])))->toMatchArray(['completed' => true, 'outcome' => 'approved'])
        ->and($voting->evaluate($node, votingSteps(['approved', 'approved', 'rejected', 'rejected', 'rejected'])))->toMatchArray(['completed' => true, 'outcome' => 'rejected']);
});

test('in percent mode the node completes at the configured share of approvers', function (): void {
    $voting = new VotingThresholdEvaluationService;
    $node = votingNode('supermajority', ['voting_threshold_percentage' => '75', 'minimum_votes_required' => 1]);

    expect($voting->evaluate($node, votingSteps(['approved', 'approved', 'approved', null]))['percentage'])->toBe(75.0)
        ->and($voting->evaluate($node, votingSteps(['approved', 'approved', 'approved', null]))['outcome'])->toBe('approved')
        ->and($voting->evaluate($node, votingSteps(['approved', 'approved', null, null]))['completed'])->toBeFalse();
});

test('a majority needs more than half of the approvers', function (): void {
    $voting = new VotingThresholdEvaluationService;
    $node = votingNode('majority');

    expect($voting->evaluate($node, votingSteps(['approved', 'approved', null, null]))['completed'])->toBeFalse()
        ->and($voting->evaluate($node, votingSteps(['approved', 'approved', 'approved', null]))['outcome'])->toBe('approved');
});

/**
 * A Monday-to-Friday calendar, 08:30 to 17:30, with the given holidays.
 *
 * @param  list<string>  $holidays
 */
function workingCalendar(Tenant $tenant, string $timezone, array $holidays): BusinessCalendar
{
    $calendar = BusinessCalendar::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'name' => 'Calendar '.$timezone,
        'timezone' => $timezone,
        'work_hours_start' => '08:30:00',
        'work_hours_end' => '17:30:00',
    ]);

    foreach ($holidays as $date) {
        BusinessCalendarHoliday::create(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'business_calendar_id' => $calendar->id, 'holiday_date' => $date, 'name' => 'Holiday '.$date]);
    }

    // Read back so the working-day columns carry their database defaults.
    return $calendar->refresh();
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['id' => (string) Str::uuid7(), 'name' => 'Clock AG', 'legal_name' => 'Clock AG', 'status' => 'active']);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->calculator = app(BusinessCalendarCalculationService::class);
    $this->factory = new HolidayCalendarFactory;
});

test('working hours on a swiss calendar skip the weekend and the christmas holidays', function (): void {
    $holidays = array_column($this->factory->holidays('CH', 2026, 'ZH'), 'date');
    $calendar = workingCalendar($this->tenant, 'Europe/Zurich', $holidays);

    // Thursday 24 Dec 2026, 16:30 in Zurich (15:30 UTC): one hour is left that day, then 25 and 26 Dec are holidays,
    // the weekend follows and the remaining three hours run on Monday 28 Dec from 08:30 (07:30 UTC) to 11:30 (10:30 UTC).
    $deadline = $this->calculator->calculateDeadline(Carbon::parse('2026-12-24 15:30:00', 'UTC'), 4, $calendar);

    expect($deadline->toDateTimeString())->toBe('2026-12-28 10:30:00');
});

test('working hours on an italian calendar skip the national holidays and the patron saint', function (): void {
    $holidays = array_column($this->factory->holidays('IT', 2026, null, 'roma'), 'date');
    $calendar = workingCalendar($this->tenant, 'Europe/Rome', $holidays);

    // Friday 26 Jun 2026 at 16:30 Rome (14:30 UTC): one hour that day, Monday 29 Jun is the patron saint of Rome,
    // so the remaining two hours run on Tuesday 30 Jun from 08:30 (06:30 UTC) to 10:30 (08:30 UTC).
    $deadline = $this->calculator->calculateDeadline(Carbon::parse('2026-06-26 14:30:00', 'UTC'), 3, $calendar);

    expect($deadline->toDateTimeString())->toBe('2026-06-30 08:30:00');
});

test('a deadline inside one working day does not move across days', function (): void {
    $calendar = workingCalendar($this->tenant, 'Europe/Zurich', []);

    $deadline = $this->calculator->calculateDeadline(Carbon::parse('2026-03-10 08:00:00', 'UTC'), 2, $calendar);

    // 09:00 Zurich (UTC+1) is outside work hours until 08:30... the clock starts at 09:00 and ends at 11:00 Zurich = 10:00 UTC.
    expect($deadline->toDateTimeString())->toBe('2026-03-10 10:00:00');
});

test('business time is false on weekends and holidays and true inside the working window', function (): void {
    $calendar = workingCalendar($this->tenant, 'Europe/Zurich', ['2026-08-03']);

    expect($this->calculator->isBusinessTime(Carbon::parse('2026-08-02 10:00:00', 'UTC'), $calendar))->toBeFalse()
        ->and($this->calculator->isBusinessTime(Carbon::parse('2026-08-03 10:00:00', 'UTC'), $calendar))->toBeFalse()
        ->and($this->calculator->isBusinessTime(Carbon::parse('2026-08-04 10:00:00', 'UTC'), $calendar))->toBeTrue()
        ->and($this->calculator->isBusinessTime(Carbon::parse('2026-08-04 18:00:00', 'UTC'), $calendar))->toBeFalse();
});
