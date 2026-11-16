<?php

declare(strict_types=1);

namespace Tests\Unit\CRM;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CRM\Contracts\LeadScoringServiceInterface;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadActivityEvent;
use Modules\CRM\Models\LeadScoringRule;
use Modules\CRM\Models\LeadSetting;
use Modules\CRM\Services\LeadScoringService;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Scoring Tenant',
        'legal_name' => 'Scoring Tenant SA',
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->scoring = app(LeadScoringServiceInterface::class);

    $this->makeLead = fn (array $attributes = []): Lead => Lead::create(array_merge([
        'tenant_id' => (string) $this->tenant->id,
        'first_name' => 'Laura',
        'last_name' => 'Bianchi',
        'status' => 'new',
    ], $attributes));

    $this->makeRule = fn (array $attributes): LeadScoringRule => LeadScoringRule::create(array_merge([
        'tenant_id' => (string) $this->tenant->id,
        'name' => $attributes['code'] ?? 'rule',
        'is_active' => true,
    ], $attributes));
});

test('falls back to the legacy heuristic when the tenant has no active rules', function (): void {
    ($this->makeRule)(['code' => 'inactive', 'category' => 'demographic', 'field' => 'industry', 'operator' => 'exists', 'points' => 50, 'is_active' => false]);

    $lead = ($this->makeLead)([
        'title' => 'Chief Executive Officer',
        'email' => 'laura@bianchi-industrie.it',
        'phone' => '+39 02 112233',
        'company_name' => 'Bianchi Industrie SpA',
        'estimated_budget' => 60000,
    ]);

    $result = $this->scoring->evaluate($lead);

    // CEO (+20) + business email (+15) + phone (+10) + company (+15) + budget >= 50k (+30)
    expect($result->score)->toBe(90)
        ->and($result->rating)->toBe('hot')
        ->and($result->breakdown)->toBe([['rule_id' => null, 'code' => 'legacy_heuristic', 'points' => 90, 'applications' => 1]])
        ->and($this->scoring->legacyScore($lead))->toBe(90);
});

test('sums matching demographic rules and derives the rating from default thresholds', function (): void {
    ($this->makeRule)(['code' => 'industry', 'category' => 'demographic', 'field' => 'industry', 'operator' => 'eq', 'value' => 'Manufacturing', 'points' => 30]);
    ($this->makeRule)(['code' => 'size', 'category' => 'demographic', 'field' => 'employee_count', 'operator' => 'gte', 'value' => 50, 'points' => 20]);
    ($this->makeRule)(['code' => 'region', 'category' => 'demographic', 'field' => 'state_region', 'operator' => 'in', 'value' => ['TI', 'MI'], 'points' => 10]);
    ($this->makeRule)(['code' => 'revenue', 'category' => 'demographic', 'field' => 'annual_revenue_estimate', 'operator' => 'between', 'value' => [1000000, 10000000], 'points' => 15]);
    ($this->makeRule)(['code' => 'free_mail', 'category' => 'demographic', 'field' => 'email_domain', 'operator' => 'contains', 'value' => 'gmail', 'points' => -10]);
    ($this->makeRule)(['code' => 'no_match', 'category' => 'demographic', 'field' => 'product_line', 'operator' => 'eq', 'value' => 'erp', 'points' => 40]);

    $lead = ($this->makeLead)([
        'industry' => 'manufacturing',
        'employee_count' => 120,
        'state_region' => 'TI',
        'annual_revenue_estimate' => 5000000,
        'email' => 'laura@gmail.com',
    ]);

    $result = $this->scoring->evaluate($lead);
    $codes = array_column($result->breakdown, 'code');

    expect($result->score)->toBe(65)
        ->and($result->rating)->toBe('warm')
        ->and($codes)->toEqualCanonicalizing(['industry', 'size', 'region', 'revenue', 'free_mail'])
        ->and($codes)->not->toContain('no_match');
});

test('uses the tenant thresholds for cold, warm and hot ratings', function (): void {
    ($this->makeRule)(['code' => 'industry', 'category' => 'demographic', 'field' => 'industry', 'operator' => 'exists', 'points' => 30]);
    LeadSetting::create(['tenant_id' => (string) $this->tenant->id, 'warm_threshold' => 10, 'hot_threshold' => 25]);

    $hot = $this->scoring->evaluate(($this->makeLead)(['industry' => 'retail']));
    $cold = $this->scoring->evaluate(($this->makeLead)());

    expect($hot->score)->toBe(30)
        ->and($hot->rating)->toBe('hot')
        ->and($cold->score)->toBe(0)
        ->and($cold->rating)->toBe('cold');
});

test('counts recent behavioural events capped by max_applications and ignores decayed ones', function (): void {
    ($this->makeRule)(['code' => 'opens', 'category' => 'behavioral', 'event_type' => 'email_opened', 'operator' => 'exists', 'points' => 5, 'max_applications' => 3, 'decay_days' => 30]);
    ($this->makeRule)(['code' => 'pricing', 'category' => 'behavioral', 'event_type' => 'link_clicked', 'field' => 'url', 'operator' => 'contains', 'value' => 'pricing', 'points' => 10]);

    $lead = ($this->makeLead)();
    foreach (range(1, 5) as $day) {
        LeadActivityEvent::create(['tenant_id' => (string) $this->tenant->id, 'lead_id' => $lead->id, 'type' => 'email_opened', 'occurred_at' => now()->subDays($day)]);
    }
    LeadActivityEvent::create(['tenant_id' => (string) $this->tenant->id, 'lead_id' => $lead->id, 'type' => 'email_opened', 'occurred_at' => now()->subDays(60)]);
    LeadActivityEvent::create(['tenant_id' => (string) $this->tenant->id, 'lead_id' => $lead->id, 'type' => 'link_clicked', 'occurred_at' => now(), 'metadata' => ['url' => 'https://example.com/pricing']]);
    LeadActivityEvent::create(['tenant_id' => (string) $this->tenant->id, 'lead_id' => $lead->id, 'type' => 'link_clicked', 'occurred_at' => now(), 'metadata' => ['url' => 'https://example.com/blog']]);

    $result = $this->scoring->evaluate($lead);
    $byCode = collect($result->breakdown)->keyBy('code');

    expect($result->score)->toBe(25)
        ->and($byCode['opens']['applications'])->toBe(3)
        ->and($byCode['opens']['points'])->toBe(15)
        ->and($byCode['pricing']['applications'])->toBe(1)
        ->and($byCode['pricing']['points'])->toBe(10);
});

test('clamps the score between 0 and 100', function (): void {
    ($this->makeRule)(['code' => 'a', 'category' => 'demographic', 'field' => 'industry', 'operator' => 'exists', 'points' => 80]);
    ($this->makeRule)(['code' => 'b', 'category' => 'demographic', 'field' => 'country', 'operator' => 'exists', 'points' => 80]);
    ($this->makeRule)(['code' => 'c', 'category' => 'demographic', 'field' => 'website', 'operator' => 'exists', 'value' => false, 'points' => -100]);

    $high = $this->scoring->evaluate(($this->makeLead)(['industry' => 'it', 'country' => 'CH', 'website' => 'https://x.ch']));
    $low = $this->scoring->evaluate(($this->makeLead)(['country' => 'CH']));

    expect($high->score)->toBe(100)
        ->and($low->score)->toBe(0);
});

test('rescore persists score, rating, breakdown and emits a score changed event', function (): void {
    ($this->makeRule)(['code' => 'industry', 'category' => 'demographic', 'field' => 'industry', 'operator' => 'eq', 'value' => 'pharma', 'points' => 75]);
    $lead = ($this->makeLead)(['industry' => 'Pharma', 'score' => 0, 'rating' => 'cold']);

    $rescored = $this->scoring->rescore($lead);
    $fresh = $lead->fresh();

    expect($rescored->score)->toBe(75)
        ->and($fresh->score)->toBe(75)
        ->and($fresh->rating)->toBe('hot')
        ->and($fresh->score_breakdown[0]['code'])->toBe('industry')
        ->and($fresh->scored_at)->not->toBeNull();

    expect(TransactionalOutbox::where('event_type', 'crm.lead.score_changed')->where('aggregate_id', (string) $lead->id)->exists())->toBeTrue();
});

test('evaluates rule operators consistently', function (mixed $actual, string $operator, mixed $expected, bool $matches): void {
    $service = app(LeadScoringService::class);

    expect($service->compare($actual, $operator, $expected))->toBe($matches);
})->with([
    'eq case insensitive' => ['Retail', 'eq', 'retail', true],
    'eq numeric' => ['50', 'eq', 50, true],
    'eq mismatch' => ['retail', 'eq', 'pharma', false],
    'in' => ['MI', 'in', ['TI', 'MI'], true],
    'in mismatch' => ['ZH', 'in', ['TI', 'MI'], false],
    'gte' => [100, 'gte', 50, true],
    'lte' => [100, 'lte', 50, false],
    'between list' => [5, 'between', [1, 10], true],
    'between map' => [11, 'between', ['min' => 1, 'max' => 10], false],
    'between open max' => [11, 'between', ['min' => 10], true],
    'contains' => ['Head of Purchasing', 'contains', 'purchasing', true],
    'contains any' => ['CTO', 'contains', ['ceo', 'cto'], true],
    'exists' => ['x', 'exists', null, true],
    'exists blank' => ['', 'exists', true, false],
    'not exists' => [null, 'exists', false, true],
    'missing never matches' => [null, 'gte', 1, false],
    'unknown operator' => ['x', 'regex', '.*', false],
]);
