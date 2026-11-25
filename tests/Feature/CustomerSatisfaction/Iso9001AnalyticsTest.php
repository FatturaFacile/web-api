<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Modules\CustomerSatisfaction\Models\DetractorRecoveryCase;
use Modules\CustomerSatisfaction\Models\Iso9001SatisfactionReport;

require_once __DIR__.'/CustomerSatisfactionSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    csSetUp($this);
    $this->nps = csTemplate(['code' => 'NPS-A']);
    $this->csat = csTemplate(['code' => 'CSAT-A', 'survey_type' => 'csat', 'trigger_event' => 'order_delivered']);
    $this->ces = csTemplate(['code' => 'CES-A', 'survey_type' => 'ces', 'trigger_event' => 'field_intervention_signed']);
});

/**
 * 2 promoters, 1 passive, 2 detractors (NPS 0); CSAT 5, 4, 2; CES 2, 6.
 */
function csSeedAnswers(object $test): void
{
    foreach ([10, 9] as $score) {
        csResponse($test->nps, $test->customer, $score, ['department_code' => 'support', 'branch_code' => 'lugano']);
    }
    csResponse($test->nps, $test->customer, 8, ['department_code' => 'support', 'branch_code' => 'milano']);
    csResponse($test->nps, $test->customer, 3, ['department_code' => 'logistics', 'branch_code' => 'milano']);
    csResponse($test->nps, $test->customer, 2, ['department_code' => 'logistics', 'branch_code' => 'milano', 'technician_id' => '019a0000-0000-7000-8000-000000000001']);

    foreach ([5, 4, 2] as $score) {
        csResponse($test->csat, $test->customer, $score, ['department_code' => 'support', 'product_line_code' => 'erp-cloud']);
    }

    csResponse($test->ces, $test->customer, 2, ['department_code' => 'field-service', 'product_line_code' => 'hardware']);
    csResponse($test->ces, $test->customer, 6, ['department_code' => 'field-service', 'product_line_code' => 'hardware']);
}

it('computes NPS as promoters minus detractors with the three bands, CSAT as the positive share and CES as the average effort', function (): void {
    csSeedAnswers($this);

    $kpis = $this->getJson('/api/v1/customer-satisfaction/analytics/overview')->assertOk()->json('data.kpis');

    expect($kpis['total'])->toBe(10)
        ->and(Arr::only($kpis['nps'], ['responses', 'promoters', 'passives', 'detractors', 'promoters_pct', 'passives_pct', 'detractors_pct', 'score']))
        ->toEqual(['responses' => 5, 'promoters' => 2, 'passives' => 1, 'detractors' => 2, 'promoters_pct' => 40.0, 'passives_pct' => 20.0, 'detractors_pct' => 40.0, 'score' => 0.0])
        ->and($kpis['csat']['positive_pct'])->toBe(66.7)
        ->and($kpis['csat']['average'])->toBe(3.67)
        ->and($kpis['ces']['average'])->toEqual(4.0)
        ->and($kpis['ces']['low_effort_rate'])->toEqual(50.0);
});

it('filters the KPIs by instrument, dimension and period', function (): void {
    csSeedAnswers($this);
    csResponse($this->nps, $this->customer, 10, ['department_code' => 'support'], 200);

    $this->getJson('/api/v1/customer-satisfaction/analytics/overview?survey_type=nps&department_code=logistics')->assertOk()
        ->assertJsonPath('data.kpis.nps.responses', 2)->assertJsonPath('data.kpis.nps.detractors', 2);

    $this->getJson('/api/v1/customer-satisfaction/analytics/overview')->assertOk()->assertJsonPath('data.kpis.nps.responses', 5);

    $from = now()->subDays(250)->toDateString();
    $this->getJson('/api/v1/customer-satisfaction/analytics/overview?from='.$from)->assertOk()->assertJsonPath('data.kpis.nps.responses', 6);
});

it('breaks the results down by department, branch, product line and technician', function (): void {
    csSeedAnswers($this);

    $byDepartment = collect($this->getJson('/api/v1/customer-satisfaction/analytics/breakdown?group_by=department&survey_type=nps')->assertOk()->json('data.rows'))->keyBy('key');
    expect($byDepartment['support']['nps']['score'])->toBe(66.7)
        ->and($byDepartment['support']['total'])->toBe(3)
        ->and($byDepartment['logistics']['nps']['score'])->toEqual(-100.0);

    $byBranch = collect($this->getJson('/api/v1/customer-satisfaction/analytics/breakdown?group_by=branch&survey_type=nps')->json('data.rows'))->keyBy('key');
    expect($byBranch['lugano']['total'])->toBe(2)->and($byBranch['milano']['total'])->toBe(3);

    $byLine = collect($this->getJson('/api/v1/customer-satisfaction/analytics/breakdown?group_by=product_line')->json('data.rows'))->keyBy('key');
    expect($byLine['hardware']['ces']['average'])->toEqual(4.0)->and($byLine['erp-cloud']['csat']['responses'])->toBe(3);

    $byTechnician = $this->getJson('/api/v1/customer-satisfaction/analytics/breakdown?group_by=technician')->json('data.rows');
    expect(collect($byTechnician)->pluck('key')->all())->toContain('019a0000-0000-7000-8000-000000000001');
});

it('draws the trend by month and quarter', function (): void {
    csSeedAnswers($this);
    csResponse($this->nps, $this->customer, 10, [], 40);
    csResponse($this->nps, $this->customer, 10, [], 75);

    $months = $this->getJson('/api/v1/customer-satisfaction/analytics/trend?granularity=month')->assertOk()->json('data.rows');

    expect(count($months))->toBeGreaterThanOrEqual(2)
        ->and(collect($months)->pluck('bucket')->sort()->values()->all())->toBe(collect($months)->pluck('bucket')->all())
        ->and(collect($months)->sum('total'))->toBe(12);

    $quarters = $this->getJson('/api/v1/customer-satisfaction/analytics/trend?granularity=quarter')->json('data.rows');
    expect($quarters[0]['bucket'])->toMatch('/^\d{4}-Q[1-4]$/');
});

it('aggregates the sentiment and the recurring themes of the open comments', function (): void {
    csResponse($this->nps, $this->customer, 2, ['comment_text' => 'Delivery was late, very bad support', 'sentiment_label' => 'negative', 'sentiment_score' => -1, 'sentiment_topics' => ['delivery', 'support']]);
    csResponse($this->nps, $this->customer, 3, ['comment_text' => 'Late delivery again', 'sentiment_label' => 'negative', 'sentiment_score' => -0.5, 'sentiment_topics' => ['delivery']]);
    csResponse($this->nps, $this->customer, 10, ['comment_text' => 'Excellent technician', 'sentiment_label' => 'positive', 'sentiment_score' => 1, 'sentiment_topics' => ['staff']]);
    csResponse($this->nps, $this->customer, 9);

    $data = $this->getJson('/api/v1/customer-satisfaction/analytics/sentiment')->assertOk()->json('data');

    expect($data['analyzed'])->toBe(3)
        ->and($data['labels'])->toMatchArray(['positive' => 1, 'neutral' => 0, 'negative' => 2])
        ->and($data['average_score'])->toBe(-0.17)
        ->and($data['topics'][0])->toBe(['topic' => 'delivery', 'count' => 2])
        ->and(collect($data['keywords'])->pluck('keyword')->all())->toContain('delivery');
});

it('measures the response rate and the closed-loop SLA adherence', function (): void {
    csSeedAnswers($this);
    csDispatch($this->nps, $this->customer, ['status' => 'sent']);
    csDispatch($this->nps, $this->customer, ['status' => 'throttled']);

    // Contacted before the deadline, contacted after it, past the deadline untouched, deadline still ahead.
    recoveryCase($this, 'in_progress', now()->subHours(30), now()->subHours(20));
    recoveryCase($this, 'resolved', now()->subHours(10), now()->subHours(14));
    recoveryCase($this, 'open', null, now()->subHours(2));
    recoveryCase($this, 'open', null, now()->addHours(10));

    $overview = $this->getJson('/api/v1/customer-satisfaction/analytics/overview')->assertOk()->json('data');

    // 10 seeded answers + the 4 answers behind the cases, plus one invitation sent and one throttled (throttled never reached anyone).
    expect($overview['response_rate']['sent'])->toBe(15)
        ->and($overview['response_rate']['responded'])->toBe(14)
        ->and($overview['response_rate']['rate'])->toBe(93.3)
        ->and($overview['closed_loop']['total'])->toBe(4)
        ->and($overview['closed_loop']['open'])->toBe(3)
        ->and($overview['closed_loop']['resolved'])->toBe(1)
        ->and($overview['closed_loop']['resolution_rate'])->toEqual(25.0)
        ->and($overview['closed_loop']['sla_evaluated'])->toBe(3)
        ->and($overview['closed_loop']['sla_compliant'])->toBe(1)
        ->and($overview['closed_loop']['sla_compliance'])->toBe(33.3)
        ->and($overview['closed_loop']['open_overdue'])->toBe(1);
});

/**
 * A recovery case written directly: its status, when it was first contacted and its SLA deadline.
 */
function recoveryCase(object $test, string $status, ?Carbon $contactedAt, Carbon $slaDueAt): DetractorRecoveryCase
{
    $response = csResponse($test->nps, $test->customer, 2);

    return DetractorRecoveryCase::query()->create([
        'survey_response_id' => $response->id,
        'customer_id' => $test->customer->id,
        'priority' => 'high',
        'status' => $status,
        'sla_due_at' => $slaDueAt,
        'first_contacted_at' => $contactedAt,
        'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? now() : null,
        'root_cause_code' => $status === 'resolved' ? 'delivery_delay' : null,
    ]);
}

it('freezes a Management Review report and exports it as JSON, CSV and printable HTML', function (): void {
    csSeedAnswers($this);

    $created = $this->postJson('/api/v1/customer-satisfaction/reports', [
        'title' => 'Riesame 2026 Q4',
        'group_by' => 'department',
        'management_review_notes' => 'Logistics needs a corrective action.',
    ])->assertCreated()->assertJsonPath('data.group_by', 'department');

    $id = $created->json('data.id');
    $report = Iso9001SatisfactionReport::query()->findOrFail($id);

    expect($report->metrics_snapshot['standard'])->toContain('ISO 9001:2015 § 9.1.2')
        ->and($report->metrics_snapshot['overview']['kpis']['total'])->toBe(10)
        ->and(collect($report->metrics_snapshot['breakdown'])->pluck('key')->all())->toContain('logistics');

    // The snapshot does not move when the live data does.
    csResponse($this->nps, $this->customer, 10);
    $this->getJson('/api/v1/customer-satisfaction/reports/'.$id)->assertOk()->assertJsonPath('data.metrics_snapshot.overview.kpis.total', 10);

    $json = $this->get('/api/v1/customer-satisfaction/reports/'.$id.'/export?format=json')->assertOk();
    expect($json->headers->get('Content-Type'))->toContain('application/json')
        ->and(json_decode($json->getContent(), true)['metrics']['overview']['kpis']['nps']['score'])->toEqual(0.0);

    $csv = $this->get('/api/v1/customer-satisfaction/reports/'.$id.'/export?format=csv')->assertOk();
    expect($csv->headers->get('Content-Type'))->toContain('text/csv')
        ->and($csv->headers->get('Content-Disposition'))->toContain('riesame-2026-q4.csv')
        ->and($csv->getContent())->toContain('logistics')->and($csv->getContent())->toContain('NPS');

    $html = $this->get('/api/v1/customer-satisfaction/reports/'.$id.'/export?format=html')->assertOk();
    expect($html->getContent())->toContain('<h1>Riesame 2026 Q4</h1>')
        ->and($html->getContent())->toContain('Logistics needs a corrective action.')
        ->and($html->getContent())->toContain('ISO 9001:2015');

    expect($report->refresh()->exported_format)->toBe('html');
    $this->get('/api/v1/customer-satisfaction/reports/'.$id.'/export?format=xml')->assertStatus(422);
    $this->getJson('/api/v1/customer-satisfaction/reports')->assertOk()->assertJsonCount(1, 'data');
});

it('counts only the answers of the active company', function (): void {
    csSeedAnswers($this);

    app(TenantContext::class)->runWithTenant($this->otherTenant, function (): void {
        $customer = csCustomer('Cliente altrui');
        $template = csTemplate(['code' => 'NPS-FOREIGN']);
        csResponse($template, $customer, 10);
        csResponse($template, $customer, 10);
    });

    $this->getJson('/api/v1/customer-satisfaction/analytics/overview')->assertOk()->assertJsonPath('data.kpis.total', 10);
    $this->getJson('/api/v1/customer-satisfaction/responses')->assertOk()->assertJsonCount(10, 'data');
});
