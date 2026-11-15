<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Configurator\Models\ProductConfiguration;
use Modules\CRM\Events\LeadCreatedEvent;
use Modules\CRM\Models\AutomationEnrollment;
use Modules\CRM\Models\AutomationSequence;
use Modules\CRM\Models\Consent;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\Partner;
use Modules\CRM\Models\PartnerTier;
use Modules\CRM\Notifications\CampaignMessageNotification;
use Modules\CRM\Services\AutomationService;
use Modules\CRM\Services\ConsentService;

uses(RefreshDatabase::class);

/**
 * CPQ (pricing rules, discount approval thresholds, quote configurations) and marketing automation sequences.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'CPQ Tenant', 'legal_name' => 'CPQ Tenant SpA']);
    app(TenantContext::class)->setTenant($this->tenant);
    cpqActAs('sales');
});

function cpqActAs(string $role): User
{
    $user = User::create(['name' => ucfirst($role), 'email' => Str::uuid().'@example.com', 'password' => Str::random(32)]);
    $user->tenants()->attach(test()->tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);
    test()->actingAs($user);
    test()->withHeader('X-Tenant-Id', (string) test()->tenant->id);

    return $user;
}

function cpqSetup(): void
{
    test()->postJson('/api/v1/crm/pricing-rules', ['name' => 'Volume 10+', 'rule_type' => 'volume_discount', 'min_quantity' => 10, 'discount_percent' => 10])->assertCreated();
    test()->postJson('/api/v1/crm/pricing-rules', ['name' => 'Volume 50+', 'rule_type' => 'volume_discount', 'min_quantity' => 50, 'discount_percent' => 15])->assertCreated();
    test()->postJson('/api/v1/crm/discount-thresholds', ['from_discount_percent' => 15, 'approver_role' => 'sales_manager'])->assertCreated();
    test()->postJson('/api/v1/crm/discount-thresholds', ['from_discount_percent' => 30, 'approver_role' => 'admin'])->assertCreated();
}

test('the best pricing rule applies and the total discount decides the approver role', function (): void {
    cpqSetup();

    $this->postJson('/api/v1/crm/quote-configurations/preview', ['name' => 'Small', 'quantity' => 5, 'unit_price' => 100])
        ->assertOk()->assertJsonPath('data.discount_percent', '0.00')->assertJsonPath('data.net_total', '500.00')->assertJsonPath('data.required_approver_role', null);
    $this->postJson('/api/v1/crm/quote-configurations/preview', ['name' => 'Volume', 'quantity' => 60, 'unit_price' => 100, 'extra_discount_percent' => 5])
        ->assertOk()->assertJsonPath('data.rule_discount_percent', '15.00')->assertJsonPath('data.discount_percent', '20.00')
        ->assertJsonPath('data.net_total', '4800.00')->assertJsonPath('data.required_approver_role', 'sales_manager');
    $this->postJson('/api/v1/crm/discount-thresholds', ['from_discount_percent' => 30, 'approver_role' => 'owner'])->assertUnprocessable()->assertJsonValidationErrors('from_discount_percent');
});

test('a quote under every threshold is approved on submission', function (): void {
    cpqSetup();
    $quote = $this->postJson('/api/v1/crm/quote-configurations', ['name' => 'Small order', 'quantity' => 5, 'unit_price' => 200])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');

    $this->postJson("/api/v1/crm/quote-configurations/{$quote['id']}/submit")->assertOk()->assertJsonPath('data.status', 'approved');
    $this->putJson("/api/v1/crm/quote-configurations/{$quote['id']}", ['quantity' => 6])->assertUnprocessable()->assertJsonValidationErrors('status');
});

test('a discount over a threshold needs an approver of that role or higher', function (): void {
    cpqSetup();
    $quote = $this->postJson('/api/v1/crm/quote-configurations', ['name' => 'Big order', 'quantity' => 60, 'unit_price' => 100, 'extra_discount_percent' => 20])->assertCreated()
        ->assertJsonPath('data.discount_percent', '35.00')->assertJsonPath('data.required_approver_role', 'admin')->json('data');
    $this->postJson("/api/v1/crm/quote-configurations/{$quote['id']}/submit")->assertOk()->assertJsonPath('data.status', 'pending_approval');

    $this->postJson("/api/v1/crm/quote-configurations/{$quote['id']}/approve")->assertForbidden();

    cpqActAs('sales_manager');
    $this->postJson("/api/v1/crm/quote-configurations/{$quote['id']}/approve")->assertUnprocessable()->assertJsonValidationErrors('status');

    cpqActAs('admin');
    $this->postJson("/api/v1/crm/quote-configurations/{$quote['id']}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/crm/quote-configurations/{$quote['id']}/reject", ['reason' => 'Too deep'])->assertOk()->assertJsonPath('data.status', 'rejected');

    $this->putJson("/api/v1/crm/quote-configurations/{$quote['id']}", ['extra_discount_percent' => 15])->assertOk()
        ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.discount_percent', '30.00');
    $this->postJson("/api/v1/crm/quote-configurations/{$quote['id']}/submit")->assertOk()->assertJsonPath('data.status', 'pending_approval');
    $this->postJson("/api/v1/crm/quote-configurations/{$quote['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.net_total', '4200.00');
});

test('partner tier rules apply only to partners of that tier', function (): void {
    $tier = PartnerTier::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'gold']);
    $partner = Partner::factory()->create(['tenant_id' => $this->tenant->id, 'tier_id' => $tier->id]);
    $plain = Partner::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->postJson('/api/v1/crm/pricing-rules', ['name' => 'Gold partners', 'rule_type' => 'partner_discount', 'min_quantity' => 0, 'partner_tier_code' => 'gold', 'discount_percent' => 12])->assertCreated();

    $this->postJson('/api/v1/crm/quote-configurations/preview', ['name' => 'Q', 'quantity' => 1, 'unit_price' => 100, 'partner_id' => $partner->id])->assertJsonPath('data.discount_percent', '12.00');
    $this->postJson('/api/v1/crm/quote-configurations/preview', ['name' => 'Q', 'quantity' => 1, 'unit_price' => 100, 'partner_id' => $plain->id])->assertJsonPath('data.discount_percent', '0.00');
});

test('a quote takes its price from a ready product configuration of the configurator', function (): void {
    $ready = ProductConfiguration::factory()->create(['tenant_id' => $this->tenant->id, 'status' => ProductConfiguration::Validated, 'quantity' => '4', 'total_net' => '2000.00', 'currency' => 'CHF']);
    $draft = ProductConfiguration::factory()->create(['tenant_id' => $this->tenant->id, 'status' => ProductConfiguration::Draft]);

    $this->postJson('/api/v1/crm/quote-configurations', ['name' => 'From configurator', 'product_configuration_id' => $ready->id])->assertCreated()
        ->assertJsonPath('data.unit_price', '500.0000')->assertJsonPath('data.quantity', '4.000')->assertJsonPath('data.currency', 'CHF')->assertJsonPath('data.net_total', '2000.00');
    $this->postJson('/api/v1/crm/quote-configurations', ['name' => 'Not ready', 'product_configuration_id' => $draft->id])->assertUnprocessable()->assertJsonValidationErrors('product_configuration_id');
    $this->postJson('/api/v1/crm/quote-configurations', ['name' => 'No price'])->assertUnprocessable()->assertJsonValidationErrors(['unit_price', 'quantity']);
});

/**
 * @return array<string, mixed>
 */
function automationSequencePayload(array $overrides = []): array
{
    return [
        'name' => 'Welcome nurture', 'entity' => 'leads', 'trigger_type' => 'manual', 'exit_conditions' => ['lead_converted', 'consent_withdrawn'],
        'steps' => [
            ['step_type' => 'email', 'delay_days' => 0, 'subject' => 'Welcome', 'body' => 'Hello and welcome'],
            ['step_type' => 'wait', 'delay_days' => 2],
            ['step_type' => 'email', 'delay_days' => 3, 'subject' => 'Follow up', 'body' => 'Any questions?'],
        ],
        ...$overrides,
    ];
}

function automationGrant(Lead $lead, string $status = 'granted'): void
{
    app(ConsentService::class)->record((string) $lead->tenant_id, ['lead_id' => $lead->id, 'channel' => 'email', 'purpose' => 'marketing', 'status' => $status, 'source' => 'web_form'], null);
}

test('steps run in order with their delays, mail goes out only with consent and every step is logged', function (): void {
    Notification::fake();
    $this->travelTo('2027-03-01 09:00:00');
    $sequence = $this->postJson('/api/v1/crm/automation-sequences', automationSequencePayload())->assertCreated()->assertJsonCount(3, 'data.steps')->json('data');
    $consenting = Lead::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'yes@example.test']);
    $silent = Lead::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'silent@example.test']);
    automationGrant($consenting);

    $a = $this->postJson("/api/v1/crm/automation-sequences/{$sequence['id']}/enroll", ['lead_id' => $consenting->id])->assertCreated()->json('data');
    $b = $this->postJson("/api/v1/crm/automation-sequences/{$sequence['id']}/enroll", ['lead_id' => $silent->id])->assertCreated()->json('data');
    $this->postJson("/api/v1/crm/automation-sequences/{$sequence['id']}/enroll", ['lead_id' => $silent->id])->assertUnprocessable()->assertJsonValidationErrors('subject');
    $this->postJson("/api/v1/crm/automation-sequences/{$sequence['id']}/enroll", ['contact_id' => Contact::factory()->create(['tenant_id' => $this->tenant->id])->id])->assertUnprocessable();

    $this->postJson('/api/v1/crm/automation-sequences/run')->assertOk()->assertJsonPath('data.handled', 2);
    Notification::assertSentTo(new AnonymousNotifiable, CampaignMessageNotification::class, fn ($notification, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'yes@example.test' && $notification->subjectLine === 'Welcome');
    Notification::assertCount(1);
    $this->getJson("/api/v1/crm/automation-enrollments/{$a['id']}/logs")->assertOk()->assertJsonPath('data.0.status', 'sent');
    $this->getJson("/api/v1/crm/automation-enrollments/{$b['id']}/logs")->assertJsonPath('data.0.status', 'skipped')->assertJsonPath('data.0.message', 'No marketing email consent');

    $this->postJson('/api/v1/crm/automation-sequences/run')->assertOk()->assertJsonPath('data.handled', 0);
    $this->travelTo('2027-03-03 10:00:00');
    $this->postJson('/api/v1/crm/automation-sequences/run')->assertJsonPath('data.handled', 2);
    $this->travelTo('2027-03-06 10:00:00');
    $this->postJson('/api/v1/crm/automation-sequences/run')->assertJsonPath('data.handled', 2);
    Notification::assertCount(2);

    $this->getJson("/api/v1/crm/automation-sequences/{$sequence['id']}/enrollments?status=completed")->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/crm/automation-enrollments/{$a['id']}/logs")->assertJsonCount(3, 'data');
});

test('exit conditions end the enrolment before the next step', function (): void {
    Notification::fake();
    $sequence = $this->postJson('/api/v1/crm/automation-sequences', automationSequencePayload())->assertCreated()->json('data');
    $converted = Lead::factory()->create(['tenant_id' => $this->tenant->id]);
    $withdrawn = Lead::factory()->create(['tenant_id' => $this->tenant->id]);
    $manual = Lead::factory()->create(['tenant_id' => $this->tenant->id]);
    automationGrant($withdrawn);
    automationGrant($withdrawn, 'withdrawn');

    $enrollments = collect([$converted, $withdrawn, $manual])->map(fn (Lead $lead) => $this->postJson("/api/v1/crm/automation-sequences/{$sequence['id']}/enroll", ['lead_id' => $lead->id])->assertCreated()->json('data'));
    $converted->forceFill(['status' => 'converted'])->save();
    $this->postJson("/api/v1/crm/automation-enrollments/{$enrollments[2]['id']}/exit")->assertOk()->assertJsonPath('data.exit_reason', 'manual');

    $this->postJson('/api/v1/crm/automation-sequences/run')->assertOk()->assertJsonPath('data.handled', 2);
    expect(AutomationEnrollment::find($enrollments[0]['id'])->exit_reason)->toBe('lead_converted')
        ->and(AutomationEnrollment::find($enrollments[1]['id'])->exit_reason)->toBe('consent_withdrawn');
    Notification::assertNothingSent();
    $this->postJson("/api/v1/crm/automation-enrollments/{$enrollments[2]['id']}/exit")->assertUnprocessable();
});

test('triggers enrol new leads in sequences honouring the segment and the entity', function (): void {
    $sequence = $this->postJson('/api/v1/crm/automation-sequences', automationSequencePayload(['trigger_type' => 'lead_created']))->assertCreated()->json('data');
    AutomationSequence::factory()->create(['tenant_id' => $this->tenant->id, 'trigger_type' => 'lead_created', 'entity' => 'leads', 'is_active' => false]);
    $lead = Lead::factory()->create(['tenant_id' => $this->tenant->id]);

    event(new LeadCreatedEvent($lead->id, 'Lead Name', null, $lead->email, 0, 'new', (string) $this->tenant->id, (string) $this->tenant->id));

    expect(AutomationEnrollment::query()->where('lead_id', $lead->id)->pluck('sequence_id')->all())->toBe([$sequence['id']]);

    event(new LeadCreatedEvent($lead->id, 'Lead Name', null, $lead->email, 0, 'new', (string) $this->tenant->id, (string) $this->tenant->id));
    expect(AutomationEnrollment::query()->where('lead_id', $lead->id)->count())->toBe(1);

    $segment = $this->postJson('/api/v1/crm/segments', ['name' => 'Swiss leads', 'entity' => 'leads', 'filters' => [['field' => 'country', 'operator' => 'eq', 'value' => 'CH']]])->assertCreated()->json();
    $scoped = $this->postJson('/api/v1/crm/automation-sequences', automationSequencePayload(['name' => 'Swiss only', 'segment_id' => $segment['id']]))->assertCreated()->json('data');
    $italian = Lead::factory()->create(['tenant_id' => $this->tenant->id, 'country' => 'IT']);
    $this->postJson("/api/v1/crm/automation-sequences/{$scoped['id']}/enroll", ['lead_id' => $italian->id])->assertUnprocessable()->assertJsonValidationErrors('subject');
    $swiss = Lead::factory()->create(['tenant_id' => $this->tenant->id, 'country' => 'CH']);
    $this->postJson("/api/v1/crm/automation-sequences/{$scoped['id']}/enroll", ['lead_id' => $swiss->id])->assertCreated();
});

test('sequence changes replace the steps and a sequence with active enrolments cannot be deleted', function (): void {
    $sequence = $this->postJson('/api/v1/crm/automation-sequences', automationSequencePayload())->assertCreated()->json('data');
    $this->postJson('/api/v1/crm/automation-sequences', automationSequencePayload(['steps' => [['step_type' => 'email', 'delay_days' => 0]]]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.subject', 'steps.0.body']);

    $this->putJson("/api/v1/crm/automation-sequences/{$sequence['id']}", ['steps' => [['step_type' => 'task', 'delay_days' => 1, 'subject' => 'Call the lead']]])->assertOk()->assertJsonCount(1, 'data.steps')->assertJsonPath('data.steps.0.position', 1);
    $lead = Lead::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->postJson("/api/v1/crm/automation-sequences/{$sequence['id']}/enroll", ['lead_id' => $lead->id])->assertCreated();
    $this->deleteJson("/api/v1/crm/automation-sequences/{$sequence['id']}")->assertUnprocessable();

    $empty = $this->postJson('/api/v1/crm/automation-sequences', automationSequencePayload(['name' => 'Empty', 'steps' => []]))->assertCreated()->json('data');
    $this->postJson("/api/v1/crm/automation-sequences/{$empty['id']}/enroll", ['lead_id' => $lead->id])->assertUnprocessable()->assertJsonValidationErrors('sequence');
    $this->deleteJson("/api/v1/crm/automation-sequences/{$empty['id']}")->assertNoContent();

    $other = Tenant::create(['name' => 'Other', 'legal_name' => 'Other SpA']);
    $foreign = AutomationSequence::factory()->create(['tenant_id' => $other->id]);
    $this->getJson("/api/v1/crm/automation-sequences/{$foreign->id}")->assertNotFound();
    expect(Consent::count())->toBe(0);
});

test('the scheduled command runs the due steps of every tenant', function (): void {
    Notification::fake();
    $sequence = $this->postJson('/api/v1/crm/automation-sequences', automationSequencePayload())->assertCreated()->json('data');
    $lead = Lead::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'cmd@example.test']);
    automationGrant($lead);
    $this->postJson("/api/v1/crm/automation-sequences/{$sequence['id']}/enroll", ['lead_id' => $lead->id])->assertCreated();

    $this->artisan('crm:process-automation')->assertSuccessful()->expectsOutputToContain('Enrolments handled: 1');
    Notification::assertCount(1);
    expect(app(AutomationService::class)->processDue((string) $this->tenant->id))->toBe(0);
});
