<?php

declare(strict_types=1);

require_once __DIR__.'/MarketingTestHelpers.php';

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\CRM\Models\Activity;
use Modules\MarketingAutomation\Application\Commands\ExecuteMarketingAutomationJourneyCommand;
use Modules\MarketingAutomation\Application\Commands\ExecuteMarketingAutomationJourneyHandler;
use Modules\MarketingAutomation\Jobs\SendMarketingMessageJob;
use Modules\MarketingAutomation\Models\Campaign;
use Modules\MarketingAutomation\Models\Enrollment;
use Modules\MarketingAutomation\Models\JourneyStep;
use Modules\MarketingAutomation\Models\MarketingMessage;
use Modules\MarketingAutomation\Models\Segment;
use Modules\MarketingAutomation\Models\StepLog;
use Modules\MarketingAutomation\Services\SegmentEvaluationService;

/**
 * @param  list<array<string, mixed>>  $steps
 */
function maJourney(Campaign $campaign, array $steps): void
{
    foreach ($steps as $step) {
        JourneyStep::withoutGlobalScopes()->create([...$step, 'tenant_id' => $campaign->tenant_id, 'campaign_id' => $campaign->id]);
    }
}

function maRun(Tenant $tenant, ?Carbon $now = null): array
{
    return maInTenant($tenant, fn () => app(ExecuteMarketingAutomationJourneyHandler::class)->handle(new ExecuteMarketingAutomationJourneyCommand((string) $tenant->id), $now));
}

/**
 * @return array{0: Tenant, 1: string, 2: Campaign, 3: string} tenant, contact, campaign, id of the account owner
 */
function maDormantJourney(): array
{
    [$tenant, $owner] = maSetup('CH');
    $ownerId = (string) $owner->id;
    $contact = maContact($tenant);
    maInvoice($tenant, $contact, Carbon::now()->subDays(100)->toDateString(), 900);
    maConsent($tenant, $contact);
    DB::table('crm_accounts')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'account_number' => 'A-1', 'name' => 'Rossi', 'contact_id' => $contact, 'owner_user_id' => $ownerId, 'created_at' => now(), 'updated_at' => now()]);

    $segment = Segment::withoutGlobalScopes()->create(['tenant_id' => (string) $tenant->id, 'name' => 'Dormienti', 'criteria' => ['recency_days_min' => 60]]);
    app(SegmentEvaluationService::class)->evaluate($segment);

    $campaign = maCampaign($tenant, [
        'trigger_type' => 'customer_dormant_60_days', 'segment_id' => $segment->id, 'channel' => 'multi_channel_journey',
        'template_mjml_json' => ['subject' => ['it' => 'Ci manchi'], 'blocks' => [['type' => 'text', 'props' => ['text' => ['it' => 'Ciao']]]]],
    ]);
    maJourney($campaign, [
        ['step_key' => 'mail', 'type' => 'send_email', 'is_entry' => true, 'next_step_key' => 'wait'],
        ['step_key' => 'wait', 'type' => 'wait', 'config' => ['days' => 3], 'next_step_key' => 'check'],
        ['step_key' => 'check', 'type' => 'condition', 'config' => ['type' => 'clicked_not_ordered'], 'true_step_key' => 'task', 'false_step_key' => 'end'],
        ['step_key' => 'task', 'type' => 'crm_task', 'config' => ['task_type' => 'call', 'due_in_days' => 2, 'subject' => ['it' => 'Richiama il cliente']], 'next_step_key' => 'end'],
        ['step_key' => 'end', 'type' => 'end'],
    ]);

    return [$tenant, $contact, $campaign, $ownerId];
}

test('a dormant customer is enrolled, mailed and parked on the wait step', function (): void {
    Queue::fake();
    [$tenant, $contact, $campaign] = maDormantJourney();

    $result = maRun($tenant);

    $enrollment = Enrollment::withoutGlobalScopes()->where('campaign_id', $campaign->id)->first();
    expect($result)->toBe(['enrolled' => 1, 'steps_executed' => 2])
        ->and($enrollment->contact_id)->toBe($contact)
        ->and($enrollment->current_step_key)->toBe('check')
        ->and($enrollment->status)->toBe('active')
        ->and($enrollment->next_run_at->greaterThan(now()->addHours(70)))->toBeTrue()
        ->and(MarketingMessage::withoutGlobalScopes()->where('campaign_id', $campaign->id)->where('status', 'queued')->count())->toBe(1)
        ->and(StepLog::withoutGlobalScopes()->where('campaign_id', $campaign->id)->pluck('outcome')->all())->toBe(['queued', 'waiting']);
    Queue::assertPushed(SendMarketingMessageJob::class, 1);

    // Nothing is due yet: running again neither re-enrols nor repeats the mail.
    expect(maRun($tenant))->toBe(['enrolled' => 0, 'steps_executed' => 0]);
    Queue::assertPushed(SendMarketingMessageJob::class, 1);
});

test('after the wait a click without an order creates a call task for the area agent and ends the journey', function (): void {
    Queue::fake();
    [$tenant, $contact, $campaign, $ownerId] = maDormantJourney();
    maRun($tenant);
    MarketingMessage::withoutGlobalScopes()->where('campaign_id', $campaign->id)->update(['first_clicked_at' => now(), 'first_opened_at' => now(), 'sent_at' => now()]);

    $result = maRun($tenant, Carbon::now()->addDays(4));

    $activity = Activity::withoutGlobalScopes()->where('related_id', $contact)->first();
    expect($result['steps_executed'])->toBe(3)
        ->and($activity)->not->toBeNull()
        ->and($activity->subject)->toBe('Richiama il cliente')
        ->and($activity->type)->toBe('call')
        ->and($activity->assigned_user_id)->toBe($ownerId)
        ->and($activity->related_type)->toBe('contact')
        ->and(Enrollment::withoutGlobalScopes()->where('campaign_id', $campaign->id)->value('status'))->toBe('completed');
});

test('without a click the false branch ends the journey and no task is created', function (): void {
    Queue::fake();
    [$tenant, $contact, $campaign] = maDormantJourney();
    maRun($tenant);

    maRun($tenant, Carbon::now()->addDays(4));

    expect(Activity::withoutGlobalScopes()->where('related_id', $contact)->count())->toBe(0)
        ->and(Enrollment::withoutGlobalScopes()->where('campaign_id', $campaign->id)->value('status'))->toBe('completed')
        ->and(StepLog::withoutGlobalScopes()->where('campaign_id', $campaign->id)->where('step_key', 'check')->value('outcome'))->toBe('false');
});

test('a click followed by an order is not chased: the condition clicked-not-ordered is false', function (): void {
    Queue::fake();
    [$tenant, $contact, $campaign] = maDormantJourney();
    maRun($tenant);
    MarketingMessage::withoutGlobalScopes()->where('campaign_id', $campaign->id)->update(['first_clicked_at' => now()]);
    DB::table('sales_orders')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'contact_id' => $contact, 'order_number' => 'SO-9', 'date' => now()->toDateString(), 'total_net' => 10, 'total_tax' => 0, 'total_gross' => 10, 'created_at' => now()->addMinute(), 'updated_at' => now()]);

    maRun($tenant, Carbon::now()->addDays(4));

    expect(Activity::withoutGlobalScopes()->where('related_id', $contact)->count())->toBe(0);
});

test('a recipient who is no longer allowed is skipped and logged, and the journey still completes', function (): void {
    Queue::fake();
    [$tenant, $contact, $campaign] = maDormantJourney();
    DB::table('ma_consents')->where('contact_id', $contact)->update(['unsubscribed_at' => now()]);
    // The unsubscribed contact would not be in a fresh audience, but the segment was evaluated earlier.

    maRun($tenant);

    Queue::assertNothingPushed();
    expect(StepLog::withoutGlobalScopes()->where('campaign_id', $campaign->id)->where('step_key', 'mail')->first())
        ->outcome->toBe('skipped')
        ->detail->toBe('unsubscribed');
});

test('a scheduled broadcast without a designed journey sends one message and completes the campaign', function (): void {
    Queue::fake();
    [$tenant] = maSetup('CH');
    $contacts = [];
    foreach (range(1, 3) as $i) {
        $id = maContact($tenant);
        maInvoice($tenant, $id, Carbon::now()->toDateString(), 100 * $i);
        maConsent($tenant, $id);
        $contacts[] = $id;
    }
    $segment = Segment::withoutGlobalScopes()->create(['tenant_id' => (string) $tenant->id, 'name' => 'Tutti', 'criteria' => []]);
    app(SegmentEvaluationService::class)->evaluate($segment);
    $campaign = maCampaign($tenant, ['segment_id' => $segment->id, 'template_mjml_json' => ['subject' => ['it' => 'Novità'], 'blocks' => []]]);

    maRun($tenant);

    Queue::assertPushed(SendMarketingMessageJob::class, 3);
    expect($campaign->refresh()->status)->toBe('completed')->and($campaign->completed_at)->not->toBeNull();
});

test('a future scheduled broadcast does not start before its time; paused campaigns never run', function (): void {
    Queue::fake();
    [$tenant] = maSetup('CH');
    $id = maContact($tenant);
    maInvoice($tenant, $id, Carbon::now()->toDateString(), 100);
    maConsent($tenant, $id);
    $segment = Segment::withoutGlobalScopes()->create(['tenant_id' => (string) $tenant->id, 'name' => 'Tutti', 'criteria' => []]);
    app(SegmentEvaluationService::class)->evaluate($segment);
    $campaign = maCampaign($tenant, ['segment_id' => $segment->id, 'scheduled_at' => now()->addDays(2), 'template_mjml_json' => ['subject' => ['it' => 'x'], 'blocks' => []]]);

    expect(maRun($tenant)['enrolled'])->toBe(0);
    expect(maRun($tenant, Carbon::now()->addDays(3))['enrolled'])->toBe(1);

    $campaign->forceFill(['status' => 'paused'])->save();
    Enrollment::withoutGlobalScopes()->where('campaign_id', $campaign->id)->update(['status' => 'active', 'next_run_at' => now()->subHour(), 'current_step_key' => 'send']);
    expect(maRun($tenant, Carbon::now()->addDays(3)))->toBe(['enrolled' => 0, 'steps_executed' => 0]);
});

test('the A/B split assigns a stable variant to every enrolment according to the percentages', function (): void {
    Queue::fake();
    [$tenant] = maSetup('CH');
    $segment = Segment::withoutGlobalScopes()->create(['tenant_id' => (string) $tenant->id, 'name' => 'Tutti', 'criteria' => []]);
    foreach (range(1, 40) as $i) {
        $id = maContact($tenant);
        maInvoice($tenant, $id, Carbon::now()->toDateString(), 100);
        maConsent($tenant, $id);
    }
    app(SegmentEvaluationService::class)->evaluate($segment);
    $campaign = maCampaign($tenant, ['segment_id' => $segment->id, 'template_mjml_json' => ['subject' => ['it' => 'A'], 'blocks' => [], 'variants' => ['B' => ['subject' => ['it' => 'B']]]], 'ab_test_split_variant_json' => ['variants' => [['key' => 'A', 'percent' => 50], ['key' => 'B', 'percent' => 50]], 'winner_criteria' => 'open_rate']]);

    maRun($tenant);

    $variants = Enrollment::withoutGlobalScopes()->where('campaign_id', $campaign->id)->pluck('variant')->countBy()->all();
    expect(array_sum($variants))->toBe(40)->and(array_keys($variants))->each->toBeIn(['A', 'B'])
        ->and(count($variants))->toBe(2);
});

test('trigger audiences: warranty expiring, abandoned quote and post-fair leads', function (): void {
    Queue::fake();
    [$tenant] = maSetup('CH');
    $warrantyContact = maContact($tenant);
    DB::table('esm_equipment_passports')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'serial_number' => 'SN1', 'current_owner_customer_id' => $warrantyContact, 'delivery_date' => now()->subYears(2)->toDateString(), 'warranty_policy_type' => 'statutory', 'warranty_expiry_date' => now()->addDays(10)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    $quoteContact = maContact($tenant);
    DB::table('quotes')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'contact_id' => $quoteContact, 'quote_number' => 'Q-9', 'date' => now()->toDateString(), 'status' => 'sent', 'total_net' => 10, 'total_tax' => 0, 'total_gross' => 10, 'created_at' => now()->subDays(2), 'updated_at' => now()]);
    $fairContact = maContact($tenant);
    $sourceId = (string) Str::uuid7();
    DB::table('crm_lead_sources')->insert(['id' => $sourceId, 'tenant_id' => (string) $tenant->id, 'code' => 'fair', 'name' => 'Fiera', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('crm_leads')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => (string) $tenant->id, 'lead_source_id' => $sourceId, 'converted_contact_id' => $fairContact, 'created_at' => now()->subDays(3), 'updated_at' => now()]);
    foreach ([$warrantyContact, $quoteContact, $fairContact] as $id) {
        maConsent($tenant, $id);
    }

    $template = ['subject' => ['it' => 'x'], 'blocks' => []];
    $warranty = maCampaign($tenant, ['trigger_type' => 'warranty_expiring', 'template_mjml_json' => $template]);
    $abandoned = maCampaign($tenant, ['trigger_type' => 'abandoned_cart', 'template_mjml_json' => $template]);
    $fair = maCampaign($tenant, ['trigger_type' => 'post_fair_lead_followup', 'template_mjml_json' => $template]);

    maRun($tenant);

    $enrolled = static fn (Campaign $c): array => Enrollment::withoutGlobalScopes()->where('campaign_id', $c->id)->pluck('contact_id')->all();
    expect($enrolled($warranty))->toBe([$warrantyContact])
        ->and($enrolled($abandoned))->toBe([$quoteContact])
        ->and($enrolled($fair))->toBe([$fairContact]);
});

test('the scheduled jobs run for a tenant', function (): void {
    Queue::fake();
    [$tenant] = maSetup('CH');
    $segment = Segment::withoutGlobalScopes()->create(['tenant_id' => (string) $tenant->id, 'name' => 'Auto', 'criteria' => [], 'auto_refresh_hourly' => true]);

    Modules\MarketingAutomation\Jobs\EvaluateRfmSegmentsJob::dispatchSync((string) $tenant->id);
    Modules\MarketingAutomation\Jobs\RunMarketingJourneysJob::dispatchSync((string) $tenant->id);

    expect($segment->refresh()->last_evaluated_at)->not->toBeNull();
});
