<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\CustomerSatisfaction\Models\SurveyTemplate;
use Modules\CustomerSatisfaction\Services\SurveyTemplateDefaults;

require_once __DIR__.'/CustomerSatisfactionSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    csSetUp($this);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function csTemplatePayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'NPS-TEST',
        'name' => 'NPS test',
        'survey_type' => 'nps',
        'trigger_event' => 'ticket_closed',
        'channels' => ['email', 'sms'],
        'delay_hours' => 4,
        'allow_voice_comment' => true,
        'messages' => ['en' => ['subject' => 'Hello', 'body' => 'Dear {customer_name}'], 'de-CH' => ['subject' => 'Hallo', 'body' => 'Guten Tag {customer_name}']],
        'follow_up_config' => ['low' => ['en' => 'What went wrong?']],
        'trigger_filters' => ['branch_code' => ['lugano'], 'min_amount' => 100],
    ], $overrides);
}

it('creates a template with the 60 day cooldown and the 24 hour SLA by default', function (): void {
    $data = $this->postJson('/api/v1/customer-satisfaction/templates', csTemplatePayload())->assertCreated()->json('data');

    expect($data['cooldown_days'])->toBe(60)
        ->and($data['sla_hours'])->toBe(24)
        ->and($data['scale'])->toBe(['min' => 0, 'max' => 10])
        ->and($data['effective_detractor_threshold'])->toBe(6)
        ->and($data['channels'])->toBe(['email', 'sms'])
        ->and($data['messages']['de-CH']['subject'])->toBe('Hallo')
        ->and($data['trigger_filters']['branch_code'])->toBe(['lugano'])
        ->and($data['is_active'])->toBeTrue();
});

it('knows the default detractor threshold of each instrument', function (): void {
    $csat = $this->postJson('/api/v1/customer-satisfaction/templates', csTemplatePayload(['code' => 'CSAT-T', 'survey_type' => 'csat']))->assertCreated();
    $ces = $this->postJson('/api/v1/customer-satisfaction/templates', csTemplatePayload(['code' => 'CES-T', 'survey_type' => 'ces']))->assertCreated();

    expect($csat->json('data.effective_detractor_threshold'))->toBe(2)
        ->and($csat->json('data.scale'))->toBe(['min' => 1, 'max' => 5])
        ->and($ces->json('data.effective_detractor_threshold'))->toBe(5)
        ->and($ces->json('data.scale'))->toBe(['min' => 1, 'max' => 7]);
});

it('validates the instrument, the event, the channels and the code', function (): void {
    $this->postJson('/api/v1/customer-satisfaction/templates', csTemplatePayload(['survey_type' => 'stars']))->assertStatus(422)->assertJsonValidationErrors('survey_type');
    $this->postJson('/api/v1/customer-satisfaction/templates', csTemplatePayload(['trigger_event' => 'moon_landing']))->assertStatus(422)->assertJsonValidationErrors('trigger_event');
    $this->postJson('/api/v1/customer-satisfaction/templates', csTemplatePayload(['channels' => []]))->assertStatus(422)->assertJsonValidationErrors('channels');
    $this->postJson('/api/v1/customer-satisfaction/templates', csTemplatePayload(['channels' => ['pigeon']]))->assertStatus(422)->assertJsonValidationErrors('channels.0');
    $this->postJson('/api/v1/customer-satisfaction/templates', csTemplatePayload(['code' => 'lower case']))->assertStatus(422)->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/customer-satisfaction/templates', csTemplatePayload(['messages' => ['en' => ['subject' => 'Only subject']]]))->assertStatus(422)->assertJsonValidationErrors('messages.en.body');
    $this->postJson('/api/v1/customer-satisfaction/templates', csTemplatePayload(['cooldown_days' => -1]))->assertStatus(422)->assertJsonValidationErrors('cooldown_days');
});

it('keeps the code unique inside the company only', function (): void {
    $this->postJson('/api/v1/customer-satisfaction/templates', csTemplatePayload())->assertCreated();
    $this->postJson('/api/v1/customer-satisfaction/templates', csTemplatePayload())->assertStatus(422)->assertJsonValidationErrors('code');

    app(TenantContext::class)->runWithTenant($this->otherTenant, fn () => csTemplate(['code' => 'ELSEWHERE']));
    $this->postJson('/api/v1/customer-satisfaction/templates', csTemplatePayload(['code' => 'ELSEWHERE']))->assertCreated();
});

it('updates a template, switches it off and keeps its own code valid', function (): void {
    $id = $this->postJson('/api/v1/customer-satisfaction/templates', csTemplatePayload())->assertCreated()->json('data.id');

    $this->putJson('/api/v1/customer-satisfaction/templates/'.$id, ['cooldown_days' => 30, 'code' => 'NPS-TEST', 'is_active' => false])->assertOk()
        ->assertJsonPath('data.cooldown_days', 30)->assertJsonPath('data.is_active', false);

    $this->getJson('/api/v1/customer-satisfaction/templates?is_active=0')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/customer-satisfaction/templates?is_active=1')->assertOk()->assertJsonCount(0, 'data');
});

it('deletes a template and keeps what it sent', function (): void {
    $template = csTemplate(['code' => 'TO-DELETE']);
    $dispatch = csDispatch($template, $this->customer);

    $this->deleteJson('/api/v1/customer-satisfaction/templates/'.$template->id)->assertOk();

    $this->getJson('/api/v1/customer-satisfaction/templates/'.$template->id)->assertNotFound();
    $this->getJson('/api/v1/customer-satisfaction/dispatches/'.$dispatch->id)->assertOk()->assertJsonPath('data.template_code', null);
});

it('installs the stock NPS, CSAT and CES templates for the five trigger events, once', function (): void {
    $this->postJson('/api/v1/customer-satisfaction/templates/install-defaults')->assertOk()->assertJsonPath('data.created', 5);
    $this->postJson('/api/v1/customer-satisfaction/templates/install-defaults')->assertOk()->assertJsonPath('data.created', 0);

    $templates = SurveyTemplate::query()->get();

    expect($templates)->toHaveCount(5)
        ->and($templates->pluck('trigger_event')->map->value->sort()->values()->all())->toBe(['field_intervention_signed', 'onboarding_completed', 'order_delivered', 'semi_annual_active_client', 'ticket_closed'])
        ->and($templates->pluck('survey_type')->map->value->unique()->sort()->values()->all())->toBe(['ces', 'csat', 'nps'])
        ->and($templates->every(fn (SurveyTemplate $template): bool => $template->cooldown_days === 60 && $template->sla_hours === 24))->toBeTrue();
});

it('writes every stock template in the five locales with one follow-up question per band', function (): void {
    foreach (SurveyTemplateDefaults::definitions() as $definition) {
        expect(array_keys($definition['messages']))->toEqualCanonicalizing(['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT']);

        foreach ($definition['messages'] as $locale => $text) {
            expect(trim($text['subject']))->not->toBe('', $locale)
                ->and($text['body'])->toContain('{customer_name}');
        }

        foreach (['low', 'mid', 'high'] as $band) {
            expect(array_keys($definition['follow_up_config'][$band]))->toEqualCanonicalizing(['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT']);
        }
    }
});

it('serves the enumerations, scales, locales, root causes and team members of the screens', function (): void {
    $data = $this->getJson('/api/v1/customer-satisfaction/lookups')->assertOk()->json('data');

    expect($data['survey_types'])->toBe(['nps', 'csat', 'ces'])
        ->and($data['trigger_events'])->toBe(['ticket_closed', 'order_delivered', 'field_intervention_signed', 'onboarding_completed', 'semi_annual_active_client'])
        ->and($data['locales'])->toBe(['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT'])
        ->and($data['scales']['ces'])->toBe(['min' => 1, 'max' => 7, 'detractor_threshold' => 5])
        ->and($data['root_causes'])->toContain('delivery_delay')
        ->and(collect($data['members'])->pluck('id')->all())->toContain($this->actor->id);
});

it('keeps another company from reading or changing a template', function (): void {
    $template = csTemplate(['code' => 'MINE']);

    $stranger = csUser($this->otherTenant, 'accountant');
    Sanctum::actingAs($stranger);
    $this->withHeader('X-Tenant-Id', $this->otherTenant->id);
    app(TenantContext::class)->setTenant($this->otherTenant);

    $this->getJson('/api/v1/customer-satisfaction/templates')->assertOk()->assertJsonCount(0, 'data');
    $this->putJson('/api/v1/customer-satisfaction/templates/'.$template->id, ['name' => 'Hijacked'])->assertNotFound();
    $this->deleteJson('/api/v1/customer-satisfaction/templates/'.$template->id)->assertNotFound();
});

it('keeps the module away from roles without access', function (): void {
    $member = csUser($this->tenant, 'viewer');
    Sanctum::actingAs($member);

    $this->getJson('/api/v1/customer-satisfaction/templates')->assertForbidden();
});
