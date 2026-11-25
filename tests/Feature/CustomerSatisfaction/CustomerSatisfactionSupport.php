<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\CustomerSatisfaction\Contracts\SurveyMessageGatewayInterface;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyChannel;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyType;
use Modules\CustomerSatisfaction\Models\SurveyDispatch;
use Modules\CustomerSatisfaction\Models\SurveyResponse;
use Modules\CustomerSatisfaction\Models\SurveyTemplate;

/**
 * Two companies, an accountant (module writer) signed in on the first one, a customer with an email address and a phone, and a
 * message gateway that records what would be sent instead of mailing it.
 */
function csSetUp(object $test, string $role = 'accountant'): void
{
    $suffix = Str::upper(Str::random(5));
    $test->tenant = Tenant::create(['name' => 'Hotel Service '.$suffix, 'legal_name' => 'Hotel Service '.$suffix.' SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $test->otherTenant = Tenant::create(['name' => 'Altra Societa '.$suffix, 'legal_name' => 'Altra Societa '.$suffix.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);

    $test->actor = csUser($test->tenant, $role);
    Sanctum::actingAs($test->actor);
    $test->withHeader('X-Tenant-Id', $test->tenant->id);
    app(TenantContext::class)->setTenant($test->tenant);

    $test->customer = csCustomer('Hotel Test');
    $test->sent = [];
    $test->instance = null;

    app()->instance(SurveyMessageGatewayInterface::class, new class($test) implements SurveyMessageGatewayInterface
    {
        public function __construct(private readonly object $test) {}

        public function supports(SurveyChannel $channel): bool
        {
            return true;
        }

        public function send(SurveyChannel $channel, string $recipient, string $subject, string $body): void
        {
            $this->test->sent[] = compact('channel', 'recipient', 'subject', 'body');
        }
    });
}

function csUser(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function csCustomer(string $name, array $overrides = []): Contact
{
    return Contact::query()->create(array_merge([
        'company_name' => $name,
        'type' => 'customer',
        'country' => 'CH',
        'email' => Str::uuid().'@customer.example.com',
        'phone' => '+4179'.random_int(1000000, 9999999),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function csTemplate(array $overrides = []): SurveyTemplate
{
    return SurveyTemplate::query()->create(array_merge([
        'code' => 'NPS-'.Str::upper(Str::random(6)),
        'name' => 'Post-ticket NPS',
        'survey_type' => 'nps',
        'trigger_event' => 'ticket_closed',
        'channels' => ['email'],
        'delay_hours' => 2,
        'cooldown_days' => 60,
        'sla_hours' => 24,
        'allow_voice_comment' => true,
        'messages' => [
            'en' => ['subject' => 'How did we do?', 'body' => 'Dear {customer_name}, rate us.', 'one_click_label' => 'One click:'],
            'it-CH' => ['subject' => 'Com’è andata?', 'body' => 'Gentile {customer_name}, ci valuti.', 'one_click_label' => 'Un clic:'],
        ],
        'follow_up_config' => [
            'low' => ['en' => 'What went wrong?', 'it-CH' => 'Cosa è andato storto?'],
            'mid' => ['en' => 'What could be better?'],
            'high' => ['en' => 'What did you like?'],
        ],
        'dimensions' => ['department_code' => 'support', 'product_line_code' => 'erp-cloud', 'branch_code' => 'lugano'],
        'is_active' => true,
    ], $overrides));
}

/**
 * A survey invitation already sent, addressed by its token.
 *
 * @param  array<string, mixed>  $overrides
 */
function csDispatch(SurveyTemplate $template, Contact $customer, array $overrides = []): SurveyDispatch
{
    return SurveyDispatch::query()->create(array_merge([
        'survey_template_id' => $template->id,
        'customer_id' => $customer->id,
        'contact_id' => $customer->id,
        'trigger_event' => $template->trigger_event->value,
        'source_entity_type' => 'esm_ticket',
        'source_entity_id' => (string) Str::uuid7(),
        'channel' => 'email',
        'locale' => 'en',
        'dispatch_token' => Str::random(48),
        'status' => 'sent',
        'scheduled_for' => now()->subHours(3),
        'sent_at' => now()->subHour(),
        'metadata' => ['dimensions' => ['department_code' => 'support', 'technician_id' => null, 'product_line_code' => 'erp-cloud', 'branch_code' => 'lugano']],
    ], $overrides));
}

/**
 * An answer stored directly (the analytics tests do not need the click flow).
 *
 * @param  array<string, mixed>  $overrides
 */
function csResponse(SurveyTemplate $template, Contact $customer, int $score, array $overrides = [], int $daysAgo = 5): SurveyResponse
{
    $type = SurveyType::from($overrides['survey_type'] ?? $template->survey_type->value);
    $dispatch = csDispatch($template, $customer, ['status' => 'responded', 'responded_at' => now()->subDays($daysAgo)]);

    return SurveyResponse::query()->create(array_merge([
        'survey_dispatch_id' => $dispatch->id,
        'survey_template_id' => $template->id,
        'customer_id' => $customer->id,
        'survey_type' => $type->value,
        'score' => $score,
        'normalized_band' => $type->band($score)->value,
        'is_detractor' => $type->isDetractor($score),
        'department_code' => 'support',
        'product_line_code' => 'erp-cloud',
        'branch_code' => 'lugano',
        'submitted_via' => 'one_click_link',
        'initial_clicked_at' => Carbon::now()->subDays($daysAgo),
    ], $overrides));
}
