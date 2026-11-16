<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\CRM\Contracts\LeadServiceInterface;
use Modules\CRM\DTOs\CreateLeadDTO;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadRoutingRule;
use Modules\CRM\Models\LeadWebhookEndpoint;
use Modules\CRM\Notifications\LeadAssignedNotification;
use Modules\CRM\Services\LeadScoringService;
use Modules\CRM\Support\LeadNormalizer;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'Lead Capture Tenant', 'legal_name' => 'Lead Capture Tenant SA']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->repA = User::create(['id' => (string) Str::uuid7(), 'name' => 'Rep A', 'email' => 'rep-a-'.Str::random(6).'@example.com', 'password' => 'secret', 'is_active' => true]);
    $this->repB = User::create(['id' => (string) Str::uuid7(), 'name' => 'Rep B', 'email' => 'rep-b-'.Str::random(6).'@example.com', 'password' => 'secret', 'is_active' => true]);

    $this->leads = app(LeadServiceInterface::class);
});

function dto(object $test, array $overrides = []): CreateLeadDTO
{
    return new CreateLeadDTO(...array_merge([
        'tenantId' => (string) $test->tenant->id,
        'companyId' => (string) $test->tenant->id,
        'firstName' => 'Luca',
        'lastName' => 'Bianchi',
        'companyName' => 'Acme S.p.A.',
        'email' => 'luca@acme.it',
        'phone' => '02 1234567',
    ], $overrides));
}

test('normalizes phone numbers to E.164, VAT numbers and company names', function (): void {
    expect(LeadNormalizer::phoneE164('+39 02 1234567'))->toBe('+39021234567')
        ->and(LeadNormalizer::phoneE164('02 1234567', 'IT'))->toBe('+39021234567')
        ->and(LeadNormalizer::phoneE164('0041 91 123 45 67'))->toBe('+41911234567')
        ->and(LeadNormalizer::phoneE164('079 123 45 67', 'CH'))->toBe('+41791234567')
        ->and(LeadNormalizer::vat('IT 012.345.678.90'))->toBe('01234567890')
        ->and(LeadNormalizer::company('Acme S.p.A.'))->toBe('acme')
        ->and(LeadNormalizer::company('ACME Società S.R.L.'))->toBe('acme societa');
});

test('detects duplicates by E.164 phone, VAT number and fuzzy company name', function (): void {
    $this->leads->captureLead(dto($this, ['vatNumber' => 'IT01234567890', 'companyName' => 'Precisione Meccanica Srl', 'duplicateStrategy' => 'create']));

    $byPhone = $this->leads->detectDuplicates((string) $this->tenant->id, null, '+39 02 1234567', null);
    $byVat = $this->leads->detectDuplicates((string) $this->tenant->id, null, null, null, '01234567890');
    $byName = $this->leads->detectDuplicates((string) $this->tenant->id, null, null, 'Precisione Mecanica SPA');
    $unrelated = $this->leads->detectDuplicates((string) $this->tenant->id, null, null, 'Totally Different Ltd');

    expect($byPhone)->toHaveCount(1)
        ->and($byVat)->toHaveCount(1)
        ->and($byName)->toHaveCount(1)
        ->and($unrelated)->toHaveCount(0);
});

test('merge strategy updates the existing lead instead of creating a new one', function (): void {
    $first = $this->leads->captureLead(dto($this, ['title' => null]));

    $result = $this->leads->captureLeadWithOutcome(dto($this, [
        'title' => 'Director',
        'catalogDownloads' => 1,
        'duplicateStrategy' => 'merge',
    ]));

    expect($result->merged)->toBeTrue()
        ->and((string) $result->lead->id)->toBe((string) $first->id)
        ->and($result->lead->title)->toBe('Director')
        ->and($result->lead->catalog_downloads)->toBe(1)
        ->and(Lead::where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

test('notify strategy creates the lead linked to the original and warns its owner', function (): void {
    Notification::fake();

    $first = $this->leads->captureLead(dto($this, ['assignedUserId' => (string) $this->repA->id]));

    $result = $this->leads->captureLeadWithOutcome(dto($this, ['phone' => '+39 02 1234567', 'email' => 'other@acme.it', 'duplicateStrategy' => 'notify']));

    expect($result->merged)->toBeFalse()
        ->and((string) $result->lead->duplicate_of_id)->toBe((string) $first->id)
        ->and((string) $result->lead->assigned_user_id)->toBe((string) $this->repA->id);

    Notification::assertSentTo($this->repA, LeadAssignedNotification::class, fn ($n) => $n->duplicateOf?->id === $first->id);
});

test('behavioural events and demographic data raise the score and the rating label', function (): void {
    $lead = $this->leads->captureLead(dto($this, [
        'email' => 'cold@gmail.com', 'phone' => null, 'companyName' => null, 'duplicateStrategy' => 'create',
    ]));
    expect($lead->rating)->toBe('cold');

    $lead = $this->leads->recordBehavior($lead, 'catalog_download', 2);
    $lead = $this->leads->recordBehavior($lead, 'site_visit', 5);
    $lead = $this->leads->recordBehavior($lead, 'email_open', 5);

    // email 5 + downloads 16 + visits 10 + opens 10 = 41 (warm); add industry/revenue/region
    $lead->update(['industry' => 'manufacturing', 'estimated_revenue' => 20000000, 'region' => 'Ticino']);
    $lead = $this->leads->rescoreLead($lead);

    expect($lead->score)->toBe(41 + 10 + 15 + 5)
        ->and($lead->rating)->toBe('hot')
        ->and($lead->score_breakdown)->toHaveKeys(['catalog_downloads', 'site_visits', 'email_opens', 'industry', 'revenue', 'geography']);

    app()->setLocale('it');
    expect(LeadScoringService::labelFor('hot'))->toBe('Caldo')
        ->and(LeadScoringService::labelFor('warm'))->toBe('Tiepido')
        ->and(LeadScoringService::labelFor('cold'))->toBe('Freddo');
});

test('round-robin routing rotates leads across the team and notifies the assignee', function (): void {
    Notification::fake();

    LeadRoutingRule::create([
        'tenant_id' => (string) $this->tenant->id,
        'name' => 'Default team',
        'strategy' => 'round_robin',
        'assignee_user_ids' => [(string) $this->repA->id, (string) $this->repB->id],
    ]);

    $assigned = [];
    foreach (['Alfa Industries', 'Beta Logistics', 'Gamma Foods', 'Delta Trading'] as $i => $name) {
        $lead = $this->leads->captureLead(dto($this, ['email' => "rr{$i}@example{$i}.com", 'phone' => null, 'companyName' => $name]));
        $assigned[] = (string) $lead->assigned_user_id;
    }

    expect($assigned)->toBe([(string) $this->repA->id, (string) $this->repB->id, (string) $this->repA->id, (string) $this->repB->id]);

    Notification::assertSentTo($this->repA, LeadAssignedNotification::class);
    Notification::assertSentTo($this->repB, LeadAssignedNotification::class);
});

test('geographic rules take priority over the round-robin fallback', function (): void {
    LeadRoutingRule::create([
        'tenant_id' => (string) $this->tenant->id, 'name' => 'Ticino', 'strategy' => 'geographic', 'priority' => 10,
        'criteria' => ['regions' => ['Ticino']], 'assignee_user_ids' => [(string) $this->repB->id],
    ]);
    LeadRoutingRule::create([
        'tenant_id' => (string) $this->tenant->id, 'name' => 'Fallback', 'strategy' => 'round_robin', 'priority' => 100,
        'assignee_user_ids' => [(string) $this->repA->id],
    ]);

    $ticino = $this->leads->captureLead(dto($this, ['region' => 'ticino', 'email' => 'a@x1.ch', 'phone' => null, 'companyName' => 'Alpha One']));
    $other = $this->leads->captureLead(dto($this, ['region' => 'Lombardia', 'email' => 'b@x2.it', 'phone' => null, 'companyName' => 'Beta Two']));

    expect((string) $ticino->assigned_user_id)->toBe((string) $this->repB->id)
        ->and((string) $other->assigned_user_id)->toBe((string) $this->repA->id);
});

test('web-to-lead webhook creates a lead, rejects bad signatures and merges duplicates', function (): void {
    $endpoint = LeadWebhookEndpoint::create([
        'tenant_id' => (string) $this->tenant->id,
        'name' => 'Website', 'provider' => 'meta_ads', 'token' => (string) Str::uuid(),
        'secret' => 'topsecret', 'duplicate_strategy' => 'merge',
    ]);

    $payload = ['field_data' => [
        ['name' => 'full_name', 'values' => ['Giulia Rossi']],
        ['name' => 'email', 'values' => ['giulia@rossi-srl.it']],
        ['name' => 'phone_number', 'values' => ['+41 79 123 45 67']],
        ['name' => 'company_name', 'values' => ['Rossi Srl']],
    ]];

    $this->postJson("/api/v1/webhooks/leads/{$endpoint->token}", $payload, ['X-Webhook-Secret' => 'wrong'])->assertStatus(401);
    $this->postJson('/api/v1/webhooks/leads/'.Str::uuid(), $payload)->assertStatus(404);

    $signature = 'sha256='.hash_hmac('sha256', json_encode($payload), 'topsecret');
    $first = $this->postJson("/api/v1/webhooks/leads/{$endpoint->token}", $payload, ['X-Webhook-Signature' => $signature]);
    $first->assertStatus(201)->assertJsonPath('merged', false)->assertJsonPath('lead.first_name', 'Giulia')->assertJsonPath('lead.last_name', 'Rossi');

    $second = $this->postJson("/api/v1/webhooks/leads/{$endpoint->token}", $payload, ['X-Webhook-Secret' => 'topsecret']);
    $second->assertStatus(200)->assertJsonPath('merged', true);

    expect(Lead::where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

test('meta lead ads verification handshake echoes the challenge', function (): void {
    $endpoint = LeadWebhookEndpoint::create([
        'tenant_id' => (string) $this->tenant->id, 'name' => 'Meta', 'provider' => 'meta_ads',
        'token' => (string) Str::uuid(), 'secret' => 'verify-me',
    ]);

    $this->get("/api/v1/webhooks/leads/{$endpoint->token}?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=12345")
        ->assertOk()->assertSee('12345');

    $this->get("/api/v1/webhooks/leads/{$endpoint->token}?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=1")->assertStatus(403);
});
