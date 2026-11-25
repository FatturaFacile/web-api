<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\CustomerSatisfaction\Models\SurveyDispatch;
use Modules\CustomerSatisfaction\Models\SurveyOptOut;
use Modules\CustomerSatisfaction\Services\SurveyDispatchProcessor;

require_once __DIR__.'/CustomerSatisfactionSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    csSetUp($this);
    $this->template = csTemplate(['code' => 'NPS-TICKET', 'delay_hours' => 2]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function csTrigger(object $test, array $overrides = []): TestResponse
{
    return $test->postJson('/api/v1/customer-satisfaction/triggers', array_merge([
        'event' => 'ticket_closed',
        'customer_id' => $test->customer->id,
        'source_entity_type' => 'esm_ticket',
        'source_entity_id' => (string) Str::uuid7(),
    ], $overrides));
}

it('schedules the survey of an ERP event after the delay of the rule', function (): void {
    $data = csTrigger($this)->assertOk()->json('data');

    expect($data['status'])->toBe('scheduled')
        ->and($data['throttle_reason'])->toBeNull()
        ->and($data['channel'])->toBe('email')
        ->and($data['template_code'])->toBe('NPS-TICKET')
        ->and($data['locale'])->toBe('it-CH')
        ->and(abs(strtotime($data['scheduled_for']) - (time() + 2 * 3600)))->toBeLessThan(120);
});

it('blocks a second survey inside the 60 day cooldown and records why', function (): void {
    csTrigger($this)->assertOk()->assertJsonPath('data.status', 'scheduled');

    $second = csTrigger($this)->assertOk();

    expect($second->json('data.status'))->toBe('throttled')
        ->and($second->json('data.throttle_reason'))->toBe('cooldown_active')
        ->and(SurveyDispatch::query()->count())->toBe(2)
        ->and(SurveyDispatch::query()->where('status', 'scheduled')->count())->toBe(1);
});

it('applies the cooldown to every campaign of the customer, not only to the same template', function (): void {
    csTemplate(['code' => 'CSAT-ORDER', 'survey_type' => 'csat', 'trigger_event' => 'order_delivered']);
    csTrigger($this)->assertOk()->assertJsonPath('data.status', 'scheduled');

    csTrigger($this, ['event' => 'order_delivered'])->assertOk()->assertJsonPath('data.throttle_reason', 'cooldown_active');
});

it('surveys the customer again once the cooldown has passed', function (): void {
    $old = csDispatch($this->template, $this->customer, ['status' => 'responded', 'responded_at' => now()->subDays(61)]);
    $old->forceFill(['created_at' => now()->subDays(61)])->save();

    csTrigger($this)->assertOk()->assertJsonPath('data.status', 'scheduled');

    $recent = csDispatch($this->template, csCustomer('Altro Cliente'), ['status' => 'sent']);
    $recent->forceFill(['created_at' => now()->subDays(59)])->save();

    csTrigger($this, ['customer_id' => $recent->customer_id])->assertOk()->assertJsonPath('data.throttle_reason', 'cooldown_active');
});

it('honours a shorter cooldown configured on the template', function (): void {
    $this->template->update(['cooldown_days' => 7]);
    $old = csDispatch($this->template, $this->customer, ['status' => 'sent']);
    $old->forceFill(['created_at' => now()->subDays(8)])->save();

    csTrigger($this)->assertOk()->assertJsonPath('data.status', 'scheduled');
});

it('does not count a throttled or failed survey for the cooldown', function (): void {
    csDispatch($this->template, $this->customer, ['status' => 'throttled', 'throttle_reason' => 'cooldown_active']);
    csDispatch($this->template, $this->customer, ['status' => 'failed']);

    csTrigger($this)->assertOk()->assertJsonPath('data.status', 'scheduled');
});

it('suppresses the survey of a customer who opted out through the link', function (): void {
    $sent = csDispatch($this->template, $this->customer);

    $this->postJson('/api/v1/public/surveys/'.$sent->dispatch_token.'/opt-out')->assertOk();
    expect(SurveyOptOut::query()->where('customer_id', $this->customer->id)->exists())->toBeTrue();

    $sent->forceFill(['created_at' => now()->subDays(90)])->save();

    csTrigger($this)->assertOk()->assertJsonPath('data.status', 'suppressed')->assertJsonPath('data.throttle_reason', 'opt_out');
});

it('suppresses the survey when the customer cannot be reached on any channel of the rule', function (): void {
    $silent = csCustomer('Senza recapiti', ['email' => null, 'phone' => null]);

    csTrigger($this, ['customer_id' => $silent->id])->assertOk()->assertJsonPath('data.status', 'suppressed')->assertJsonPath('data.throttle_reason', 'missing_contact');
});

it('falls back to the next channel of the rule the customer can be reached on', function (): void {
    $this->template->update(['channels' => ['email', 'whatsapp']]);
    $phoneOnly = csCustomer('Solo telefono', ['email' => null]);

    csTrigger($this, ['customer_id' => $phoneOnly->id])->assertOk()->assertJsonPath('data.channel', 'whatsapp');
});

it('surveys the same source record only once', function (): void {
    $source = (string) Str::uuid7();

    $first = csTrigger($this, ['source_entity_id' => $source])->assertOk()->json('data.id');
    $again = csTrigger($this, ['source_entity_id' => $source])->assertOk()->json('data.id');

    expect($again)->toBe($first)->and(SurveyDispatch::query()->count())->toBe(1);
});

it('applies the filters of the rule and picks the first matching rule by priority', function (): void {
    $this->template->update(['trigger_filters' => ['branch_code' => ['zurich']], 'priority' => 10]);
    $fallback = csTemplate(['code' => 'NPS-FALLBACK', 'priority' => 50]);

    $data = csTrigger($this, ['dimensions' => ['branch_code' => 'lugano']])->assertOk()->json('data');
    expect($data['template_code'])->toBe($fallback->code);

    $other = csCustomer('Cliente Zurigo');
    $zurich = csTrigger($this, ['customer_id' => $other->id, 'dimensions' => ['branch_code' => 'zurich']])->assertOk()->json('data');
    expect($zurich['template_code'])->toBe('NPS-TICKET');
});

it('answers without a dispatch when no active rule matches the event', function (): void {
    $this->template->update(['is_active' => false]);

    csTrigger($this)->assertOk()->assertJsonPath('data', null);
});

it('sends only the invitations that are due, with one link per score on email', function (): void {
    $data = csTrigger($this)->assertOk()->json('data');

    expect(app(SurveyDispatchProcessor::class)->processDue())->toBe(['sent' => 0, 'failed' => 0, 'suppressed' => 0])
        ->and($this->sent)->toBeEmpty();

    SurveyDispatch::query()->whereKey($data['id'])->update(['scheduled_for' => now()->subMinute()]);
    $result = app(SurveyDispatchProcessor::class)->processDue();

    $dispatch = SurveyDispatch::query()->findOrFail($data['id']);

    expect($result['sent'])->toBe(1)
        ->and($dispatch->status->value)->toBe('sent')
        ->and($dispatch->sent_at)->not->toBeNull()
        ->and($this->sent)->toHaveCount(1)
        ->and($this->sent[0]['recipient'])->toBe($this->customer->email)
        ->and($this->sent[0]['body'])->toContain('/rate/0')
        ->and($this->sent[0]['body'])->toContain('/rate/10')
        ->and($this->sent[0]['body'])->toContain($dispatch->dispatch_token);
});

it('runs the due invitations from the API and never sends twice', function (): void {
    $data = csTrigger($this)->assertOk()->json('data');
    SurveyDispatch::query()->whereKey($data['id'])->update(['scheduled_for' => now()->subMinute()]);

    $this->postJson('/api/v1/customer-satisfaction/dispatches/process')->assertOk()->assertJsonPath('data.sent', 1);
    $this->postJson('/api/v1/customer-satisfaction/dispatches/process')->assertOk()->assertJsonPath('data.sent', 0);

    expect($this->sent)->toHaveCount(1);
});

it('lists the dispatches with their status and throttle reason, only of the active company', function (): void {
    csTrigger($this)->assertOk();
    csTrigger($this)->assertOk();

    $other = $this->otherTenant;
    app(TenantContext::class)->runWithTenant($other, function () use ($other): void {
        $foreign = csCustomer('Cliente altrui');
        $template = csTemplate(['code' => 'FOREIGN']);
        csDispatch($template, $foreign);
        expect($other->id)->not->toBeNull();
    });

    $this->getJson('/api/v1/customer-satisfaction/dispatches')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/customer-satisfaction/dispatches?dispatch_status=throttled')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.throttle_reason', 'cooldown_active');
});
