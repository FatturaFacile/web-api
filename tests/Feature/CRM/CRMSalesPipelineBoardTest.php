<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\SalesPipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\CRM\Events\OpportunityBoardUpdated;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\LossReason;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityStage;
use Modules\CRM\Models\OpportunityStageHistory;
use Modules\CRM\Models\Pipeline;

uses(RefreshDatabase::class);

/**
 * Module 035: multiple pipelines, Kanban board and moves, weighted values, mandatory loss questionnaire, stage history,
 * quick notes, forecast / quota dashboard and loss analysis.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Board Tenant', 'legal_name' => 'Board Tenant SA']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::create(['name' => 'Sales Manager', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'sales_manager']);
    $this->actingAs($this->user);
    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id);
    $this->withHeader('X-Language-Code', 'en');

    $contact = Contact::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->account = Account::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'account_number' => 'ACC-BOARD', 'name' => 'Board Corp', 'contact_id' => $contact->id]);

    $this->seed(SalesPipelineSeeder::class);

    $this->stage = fn (string $code): OpportunityStage => OpportunityStage::where('tenant_id', $this->tenant->id)->where('code', $code)->firstOrFail();
    $this->deal = fn (string $name, float $amount, string $stage = 'discovery', array $extra = []) => $this->postJson('/api/v1/crm/opportunities', [
        'account_id' => $this->account->id,
        'name' => $name,
        'amount' => $amount,
        'stage_code' => $stage,
        ...$extra,
    ])->assertCreated();
});

test('the seeder installs two localized pipelines, stages and loss reasons and is idempotent', function (): void {
    $this->seed(SalesPipelineSeeder::class);

    expect(Pipeline::where('tenant_id', $this->tenant->id)->count())->toBe(2)
        ->and(Pipeline::where('tenant_id', $this->tenant->id)->where('is_default', true)->count())->toBe(1)
        ->and(LossReason::where('tenant_id', $this->tenant->id)->count())->toBe(6)
        ->and(OpportunityStage::where('tenant_id', $this->tenant->id)->where('pipeline_id', Pipeline::where('code', 'italy')->value('id'))->count())->toBe(6);

    $stage = ($this->stage)('discovery');
    expect($stage->translations)->toHaveKeys(['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT'])
        ->and($stage->translations['it-CH'])->toBe('Qualificazione');
});

test('the catalog is served localized in the active language', function (): void {
    $this->withHeader('X-Language-Code', 'de');
    $reasons = collect($this->getJson('/api/v1/crm/loss-reasons')->assertOk()->json('data'));

    expect($reasons->firstWhere('code', 'price')['name'])->toBe('Preis')
        ->and($reasons->firstWhere('code', 'project_frozen')['name'])->toBe('Projekt eingefroren');

    $this->withHeader('X-Language-Code', 'fr');
    $stages = collect($this->getJson('/api/v1/crm/pipelines')->assertOk()->json('data.0.stages'));
    expect($stages->firstWhere('code', 'closed_lost')['name'])->toBe('Clôturée perdue');
});

test('pipelines can be created per business line and market with safeguards for the default and used ones', function (): void {
    $created = $this->postJson('/api/v1/crm/pipelines', [
        'code' => 'retail_ch', 'name' => 'Retail Switzerland', 'business_line' => 'retail', 'market' => 'CH', 'install_standard_stages' => true,
        'translations' => ['it-CH' => 'Retail Svizzera', 'xx' => 'ignored'],
    ])->assertCreated()->assertJsonPath('is_default', false)->assertJsonCount(6, 'stages');

    $this->postJson('/api/v1/crm/pipelines', ['code' => 'retail_ch', 'name' => 'Duplicate'])->assertUnprocessable();
    expect($created->json('translations'))->toBe(['it-CH' => 'Retail Svizzera']);

    $this->putJson('/api/v1/crm/pipelines/'.$created->json('id'), ['name' => 'Retail CH', 'is_default' => true])
        ->assertOk()->assertJsonPath('is_default', true);
    expect(Pipeline::where('tenant_id', $this->tenant->id)->where('is_default', true)->count())->toBe(1);

    $default = Pipeline::where('tenant_id', $this->tenant->id)->where('code', 'default')->firstOrFail();
    $this->putJson('/api/v1/crm/pipelines/'.$default->id, ['is_default' => true])->assertOk();

    $newDefault = Pipeline::where('tenant_id', $this->tenant->id)->where('is_default', true)->firstOrFail();
    $this->putJson('/api/v1/crm/pipelines/'.$newDefault->id, ['is_active' => false])->assertUnprocessable();
    $this->deleteJson('/api/v1/crm/pipelines/'.$newDefault->id)->assertUnprocessable();

    ($this->deal)('Used deal', 1000, 'it_discovery', ['pipeline_id' => Pipeline::where('code', 'italy')->value('id')]);
    $this->deleteJson('/api/v1/crm/pipelines/'.Pipeline::where('code', 'italy')->value('id'))->assertUnprocessable();
    $this->deleteJson('/api/v1/crm/pipelines/'.$created->json('id'))->assertNoContent();
});

test('a deal created in a pipeline starts in its first open stage and cannot move to another pipeline', function (): void {
    $italy = Pipeline::where('code', 'italy')->firstOrFail();
    $deal = ($this->deal)('Italian deal', 8000, 'unknown_code', ['pipeline_id' => $italy->id]);

    $deal->assertJsonPath('pipeline_id', $italy->id)->assertJsonPath('stage.code', 'it_discovery');

    $foreign = ($this->stage)('negotiation');
    $this->postJson('/api/v1/crm/opportunities/'.$deal->json('id').'/move', ['stage_id' => $foreign->id])->assertUnprocessable();
    $this->postJson('/api/v1/crm/opportunities/'.$deal->json('id').'/stage', ['target_stage_code' => 'negotiation'])->assertUnprocessable()->assertJsonValidationErrors('target_stage_code');
});

test('the board totals deal and weighted values per stage and filters by owner and category', function (): void {
    ($this->deal)('Alpha', 10000, 'qualification');
    ($this->deal)('Beta', 5000, 'qualification');
    ($this->deal)('Gamma', 2000, 'negotiation');

    $board = $this->getJson('/api/v1/crm/pipelines/default/board')->assertOk();
    $columns = collect($board->json('columns'))->keyBy('stage.code');

    // qualification: 25% default -> 15000 * 0.25 = 3750
    expect($columns['qualification']['totals'])->toBe(['count' => 2, 'amount' => '15000.00', 'weighted_amount' => '3750.00'])
        ->and($columns['negotiation']['totals']['weighted_amount'])->toBe('1500.00')
        ->and($board->json('totals.count'))->toBe(3)
        ->and($board->json('totals.amount'))->toBe('17000.00')
        ->and($board->json('totals.weighted_amount'))->toBe('5250.00')
        ->and(collect($board->json('columns'))->pluck('stage.code')->first())->toBe('discovery');

    $this->getJson('/api/v1/crm/pipelines/default/board?search=alp')->assertOk()->assertJsonPath('totals.count', 1);
    $this->getJson('/api/v1/crm/pipelines/default/board?forecast_category=commit')->assertOk()->assertJsonPath('totals.count', 1);
    $this->getJson('/api/v1/crm/pipelines/default/board?owner_user_id='.$this->user->id)->assertOk()->assertJsonPath('totals.count', 3);
    $this->getJson('/api/v1/crm/pipelines/default/board?owner_user_id='.Str::uuid())->assertUnprocessable();
});

test('moving a deal recalculates its weighted value, records history and keeps the column order', function (): void {
    $a = ($this->deal)('A', 1000, 'discovery');
    $b = ($this->deal)('B', 2000, 'discovery');
    $c = ($this->deal)('C', 3000, 'discovery');
    $qualification = ($this->stage)('qualification');

    $moved = $this->postJson('/api/v1/crm/opportunities/'.$a->json('id').'/move', ['stage_id' => $qualification->id, 'position' => 0])
        ->assertOk()
        ->assertJsonPath('opportunity.stage.code', 'qualification')
        ->assertJsonPath('opportunity.probability', 25)
        ->assertJsonPath('opportunity.weighted_amount', 250);

    expect($moved->json('stage_totals.'.$qualification->id.'.count'))->toBe(1)
        ->and($moved->json('stage_totals.'.($this->stage)('discovery')->id.'.count'))->toBe(2)
        ->and(OpportunityStageHistory::where('opportunity_id', $a->json('id'))->count())->toBe(2);

    // Custom deal probability overrides the stage default and is weighted exactly.
    $this->postJson('/api/v1/crm/opportunities/'.$b->json('id').'/move', ['stage_id' => $qualification->id, 'position' => 0, 'probability' => 33.33])
        ->assertOk()->assertJsonPath('opportunity.weighted_amount', 666.6);

    $order = collect($this->getJson('/api/v1/crm/pipelines/default/board')->json('columns'))->firstWhere('stage.code', 'qualification')['opportunities'];
    expect(collect($order)->pluck('name')->all())->toBe(['B', 'A']);

    // Reordering inside the same column does not create a history row.
    $this->postJson('/api/v1/crm/opportunities/'.$a->json('id').'/move', ['stage_id' => $qualification->id, 'position' => 0])->assertOk();
    $order = collect($this->getJson('/api/v1/crm/pipelines/default/board')->json('columns'))->firstWhere('stage.code', 'qualification')['opportunities'];
    expect(collect($order)->pluck('name')->all())->toBe(['A', 'B'])
        ->and(OpportunityStageHistory::where('opportunity_id', $a->json('id'))->count())->toBe(2);

    $history = $this->getJson('/api/v1/crm/opportunities/'.$a->json('id').'/history')->assertOk()->json('data');
    expect($history)->toHaveCount(2)->and($history[0]['to_stage'])->toBe('Solution Presentation');

    expect(Opportunity::find($c->json('id'))->stage_id)->toBe(($this->stage)('discovery')->id);
});

test('the stage default forecast category applies on a move unless one is given', function (): void {
    $deal = ($this->deal)('Cat', 1000, 'discovery');

    $this->postJson('/api/v1/crm/opportunities/'.$deal->json('id').'/move', ['stage_id' => ($this->stage)('negotiation')->id])
        ->assertOk()->assertJsonPath('opportunity.forecast_category', 'commit');
    $this->postJson('/api/v1/crm/opportunities/'.$deal->json('id').'/move', ['stage_id' => ($this->stage)('proposal')->id, 'forecast_category' => 'pipeline'])
        ->assertOk()->assertJsonPath('opportunity.forecast_category', 'pipeline');
});

test('closing as lost requires a catalog loss reason and the winning competitor and changes nothing otherwise', function (): void {
    $deal = ($this->deal)('Doomed', 20000, 'negotiation');
    $lost = ($this->stage)('closed_lost');
    $url = '/api/v1/crm/opportunities/'.$deal->json('id').'/move';

    $this->postJson($url, ['stage_id' => $lost->id])->assertUnprocessable()->assertJsonValidationErrors(['loss_reason_code', 'competitor']);
    $this->postJson($url, ['stage_id' => $lost->id, 'loss_reason_code' => 'price'])->assertUnprocessable()->assertJsonValidationErrors(['competitor']);
    $this->postJson($url, ['stage_id' => $lost->id, 'loss_reason_code' => 'nope', 'competitor' => 'Rival'])->assertUnprocessable()->assertJsonValidationErrors(['loss_reason_code']);
    $this->postJson($url, ['stage_id' => $lost->id, 'loss_reason_code' => 'price', 'competitor' => '   '])->assertUnprocessable();
    $this->postJson('/api/v1/crm/opportunities/'.$deal->json('id').'/stage', ['target_stage_code' => 'closed_lost', 'win_loss_reason' => 'free text', 'competitor' => 'Rival'])
        ->assertUnprocessable()->assertJsonValidationErrors(['loss_reason_code']);

    $untouched = Opportunity::findOrFail($deal->json('id'));
    expect($untouched->status)->toBe('open')->and($untouched->stage->code)->toBe('negotiation')
        ->and(OpportunityStageHistory::where('opportunity_id', $untouched->id)->count())->toBe(1);

    $this->postJson($url, ['stage_id' => $lost->id, 'loss_reason_code' => 'price', 'competitor' => 'Rival SA', 'loss_notes' => 'They were 20% cheaper'])
        ->assertOk()
        ->assertJsonPath('opportunity.status', 'lost')
        ->assertJsonPath('opportunity.probability', 0)
        ->assertJsonPath('opportunity.weighted_amount', 0)
        ->assertJsonPath('opportunity.loss_reason_code', 'price')
        ->assertJsonPath('opportunity.competitor', 'Rival SA');

    $row = OpportunityStageHistory::where('opportunity_id', $deal->json('id'))->where('to_stage_id', $lost->id)->firstOrFail();
    expect($row->loss_reason_code)->toBe('price')->and($row->winning_competitor)->toBe('Rival SA');
});

test('reopening a lost deal clears the loss data', function (): void {
    $deal = ($this->deal)('Comeback', 9000, 'negotiation');
    $this->postJson('/api/v1/crm/opportunities/'.$deal->json('id').'/move', ['stage_id' => ($this->stage)('closed_lost')->id, 'loss_reason_code' => 'project_frozen', 'competitor' => 'None'])->assertOk();

    $this->postJson('/api/v1/crm/opportunities/'.$deal->json('id').'/move', ['stage_id' => ($this->stage)('qualification')->id])
        ->assertOk()
        ->assertJsonPath('opportunity.status', 'open')
        ->assertJsonPath('opportunity.loss_reason_code', null)
        ->assertJsonPath('opportunity.forecast_category', 'pipeline')
        ->assertJsonPath('opportunity.competitor', null);
});

test('a loss reason that requires a competitor is enforced and a used reason cannot be deleted', function (): void {
    $reason = $this->postJson('/api/v1/crm/loss-reasons', ['code' => 'lost_to_incumbent', 'name' => 'Incumbent', 'requires_competitor' => true, 'translations' => ['en' => 'Incumbent']])
        ->assertCreated()->assertJsonPath('requires_competitor', true);
    $this->postJson('/api/v1/crm/loss-reasons', ['code' => 'lost_to_incumbent', 'name' => 'Dup'])->assertUnprocessable();

    $deal = ($this->deal)('Incumbent deal', 1000, 'proposal');
    $this->postJson('/api/v1/crm/opportunities/'.$deal->json('id').'/move', ['stage_id' => ($this->stage)('closed_lost')->id, 'loss_reason_code' => 'lost_to_incumbent', 'competitor' => 'ACME'])->assertOk();

    $this->deleteJson('/api/v1/crm/loss-reasons/'.$reason->json('id'))->assertUnprocessable();
    $this->putJson('/api/v1/crm/loss-reasons/'.$reason->json('id'), ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);

    $unused = $this->postJson('/api/v1/crm/loss-reasons', ['code' => 'unused', 'name' => 'Unused'])->assertCreated();
    $this->deleteJson('/api/v1/crm/loss-reasons/'.$unused->json('id'))->assertNoContent();
});

test('quick notes replace or append to the deal note', function (): void {
    $deal = ($this->deal)('Noted', 500);
    $url = '/api/v1/crm/opportunities/'.$deal->json('id').'/notes';

    $this->patchJson($url, [])->assertUnprocessable();
    $this->patchJson($url, ['notes' => 'Called the buyer'])->assertOk()->assertJsonPath('notes', 'Called the buyer');
    $appended = $this->patchJson($url, ['notes' => 'Sent the quote', 'append' => true])->assertOk()->json('notes');

    expect($appended)->toStartWith('Called the buyer')->and($appended)->toContain('Sent the quote')->and($appended)->toMatch('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}\] Sent the quote/');
});

test('board changes are broadcast on the tenant channel', function (): void {
    Event::fake([OpportunityBoardUpdated::class]);

    $deal = ($this->deal)('Live', 700);
    $this->postJson('/api/v1/crm/opportunities/'.$deal->json('id').'/move', ['stage_id' => ($this->stage)('proposal')->id])->assertOk();
    $this->patchJson('/api/v1/crm/opportunities/'.$deal->json('id').'/notes', ['notes' => 'x'])->assertOk();

    Event::assertDispatched(OpportunityBoardUpdated::class, fn (OpportunityBoardUpdated $event): bool => $event->change === 'moved' && $event->tenantId === (string) $this->tenant->id);
    Event::assertDispatched(OpportunityBoardUpdated::class, fn (OpportunityBoardUpdated $event): bool => $event->change === 'noted');
    expect((new OpportunityBoardUpdated((string) $this->tenant->id, 'o', 'moved', null, null, null))->broadcastOn()[0]->name)->toBe('private-tenant.'.$this->tenant->id.'.crm-pipeline');
});

test('the forecast dashboard buckets by month and quarter with categories, weighted value and quota tracking', function (): void {
    $year = (int) date('Y') + 1;
    $rep = User::create(['name' => 'Rep One', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $rep->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'sales']);

    $make = fn (string $name, float $amount, string $stage, string $date, string $category) => ($this->deal)($name, $amount, $stage, [
        'owner_user_id' => $rep->id, 'expected_close_date' => "{$year}-{$date}", 'forecast_category' => $category,
    ]);

    $make('Pipe', 1000, 'discovery', '01-10', 'pipeline');
    $make('Best', 2000, 'proposal', '02-10', 'best_case');
    $make('Commit', 4000, 'negotiation', '03-10', 'commit');
    $make('Won', 8000, 'negotiation', '03-20', 'commit');
    $make('Q2', 3000, 'negotiation', '05-05', 'commit');

    $won = Opportunity::where('name', 'Won')->firstOrFail();
    $this->postJson('/api/v1/crm/opportunities/'.$won->id.'/stage', ['target_stage_code' => 'closed_won', 'actual_close_date' => "{$year}-03-22"])->assertOk();

    $this->postJson('/api/v1/crm/sales-quotas', ['user_id' => $rep->id, 'period_start' => "{$year}-01-01", 'period_end' => "{$year}-03-31", 'target_amount' => '30000'])->assertCreated();

    $quarterly = $this->getJson("/api/v1/crm/forecasts/dashboard?granularity=quarter&year={$year}&quarter=1")->assertOk();
    $q1 = collect($quarterly->json('buckets'))->firstWhere('key', "{$year}-Q1");

    expect($q1['pipeline'])->toBe('1000.00')
        ->and($q1['best_case'])->toBe('2000.00')
        ->and($q1['commit'])->toBe('4000.00')
        ->and($q1['closed_won'])->toBe('8000.00')
        ->and($q1['commit_forecast'])->toBe('12000.00')
        ->and($q1['best_case_forecast'])->toBe('14000.00')
        ->and($q1['quota'])->toBe('30000.00')
        ->and(collect($quarterly->json('buckets'))->firstWhere('key', "{$year}-Q2")['commit'])->toBe('3000.00')
        ->and($quarterly->json('totals.attainment_percent'))->toBe('26.66')
        ->and($quarterly->json('totals.commit_attainment_percent'))->toBe('40.00');

    $repRow = collect($quarterly->json('reps'))->firstWhere('user_id', $rep->id);
    expect($repRow['name'])->toBe('Rep One')->and($repRow['quota'])->toBe('30000.00')->and($repRow['closed_won'])->toBe('8000.00');

    // A quarterly quota is spread over the months of the quarter by days.
    $monthly = $this->getJson("/api/v1/crm/forecasts/dashboard?granularity=month&year={$year}")->assertOk();
    $sumOfMonths = collect($monthly->json('buckets'))->take(3)->reduce(fn (string $carry, array $bucket): string => bcadd($carry, $bucket['quota'], 2), '0.00');
    expect(abs((float) $sumOfMonths - 30000.0))->toBeLessThan(0.05);

    $this->getJson("/api/v1/crm/forecasts/dashboard?year={$year}&owner_user_id=".$this->user->id)->assertOk()->assertJsonPath('totals.quota', '0.00');
    $this->getJson('/api/v1/crm/forecasts/dashboard?granularity=week')->assertUnprocessable();
});

test('the loss analysis breaks lost deals down by reason and winning competitor', function (): void {
    $lose = function (string $name, float $amount, string $reason, string $competitor) {
        $deal = ($this->deal)($name, $amount, 'negotiation');
        $this->postJson('/api/v1/crm/opportunities/'.$deal->json('id').'/move', ['stage_id' => ($this->stage)('closed_lost')->id, 'loss_reason_code' => $reason, 'competitor' => $competitor])->assertOk();
    };

    $lose('L1', 1000, 'price', 'Rival SA');
    $lose('L2', 3000, 'price', 'rival sa');
    $lose('L3', 500, 'missing_features', 'Other AG');

    $report = $this->getJson('/api/v1/crm/reports/loss-analysis')->assertOk();

    expect($report->json('total_lost'))->toBe(['count' => 3, 'amount' => '4500.00'])
        ->and($report->json('by_reason.0'))->toBe(['code' => 'price', 'label' => 'Price', 'count' => 2, 'amount' => '4000.00'])
        ->and($report->json('by_reason.1.code'))->toBe('missing_features')
        ->and($report->json('by_competitor.0.count'))->toBe(2)
        ->and($report->json('by_competitor.0.amount'))->toBe('4000.00');

    $this->getJson('/api/v1/crm/reports/loss-analysis?from=2999-01-01')->assertOk()->assertJsonPath('total_lost.count', 0);
});

test('pipelines, stages and board data never leak across tenants', function (): void {
    $other = Tenant::create(['name' => 'Other', 'legal_name' => 'Other SA']);
    $foreign = Pipeline::create(['id' => (string) Str::uuid7(), 'tenant_id' => $other->id, 'code' => 'default', 'name' => 'Foreign', 'is_default' => true]);

    $this->getJson('/api/v1/crm/pipelines/'.$foreign->id)->assertNotFound();
    $this->getJson('/api/v1/crm/pipelines/'.$foreign->id.'/board')->assertNotFound();
    $this->postJson('/api/v1/crm/opportunities', ['account_id' => $this->account->id, 'name' => 'X', 'amount' => 1, 'pipeline_id' => $foreign->id])->assertUnprocessable();
    expect(collect($this->getJson('/api/v1/crm/pipelines')->json('data'))->pluck('id')->all())->not->toContain($foreign->id);
});

test('legacy stages and deals without a pipeline are adopted by the default pipeline', function (): void {
    $legacy = Tenant::create(['name' => 'Legacy', 'legal_name' => 'Legacy SA']);
    $stage = OpportunityStage::create(['id' => (string) Str::uuid7(), 'tenant_id' => $legacy->id, 'code' => 'discovery', 'name' => 'Discovery', 'sequence' => 10, 'default_probability' => 10]);

    app(TenantContext::class)->setTenant($legacy);
    $this->user->tenants()->attach($legacy->id, ['id' => (string) Str::uuid(), 'role' => 'sales_manager']);
    $this->withHeader('X-Tenant-Id', (string) $legacy->id);

    $board = $this->getJson('/api/v1/crm/pipelines/default/board')->assertOk();

    expect($board->json('columns.0.stage.id'))->toBe($stage->id)
        ->and($stage->fresh()->pipeline_id)->toBe($board->json('pipeline.id'));
});
