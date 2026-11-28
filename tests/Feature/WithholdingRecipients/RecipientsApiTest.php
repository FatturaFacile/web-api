<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/WithholdingRecipientsSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    wrSetUp($this);
});

it('requires authentication', function (): void {
    $this->refreshApplication();

    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id)->getJson(WR_API.'/recipients')->assertUnauthorized();
});

it('lets finance and HR roles read, keeps writing to the managing roles and closes the module to the others', function (string $role, int $read, int $write): void {
    treasuryActor($this, $role);

    foreach (['recipients', 'dashboard', 'lookups', 'settlements', 'annual-returns'] as $path) {
        $this->getJson(WR_API.'/'.$path)->assertStatus($read);
    }

    $this->postJson(WR_API.'/recipients', wrRecipientPayload('author_royalty'))->assertStatus($write);
})->with([
    'owner' => ['owner', 200, 201],
    'admin' => ['admin', 200, 201],
    'accountant' => ['accountant', 200, 201],
    'HR manager' => ['hr_manager', 200, 201],
    'read-only accountant' => ['accountant_readonly', 200, 403],
    'sales' => ['sales', 403, 403],
    'plain member' => ['member', 403, 403],
]);

it('keeps posting, flows and the annual CU to owners, administrators and accountants', function (): void {
    $author = wrRecipient($this, 'author_royalty');

    treasuryActor($this, 'hr_manager');
    $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $author->id, 'gross_amount' => 1000, 'payment_date' => '2026-06-15'])->assertForbidden();
    $this->getJson(WR_API.'/flows/f24?accrual_month=2026-06')->assertForbidden();
    $this->postJson(WR_API.'/annual-returns/generate', ['tax_year' => 2026])->assertForbidden();
    $this->postJson(WR_API.'/compensations/simulate', ['recipient_profile_id' => $author->id, 'gross_amount' => 1000, 'payment_date' => '2026-06-15'])->assertOk();

    $member = User::create(['name' => 'grant', 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $member->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member', 'permissions' => json_encode(['withholding_recipients.write', 'withholding_recipients.settle'])]);
    treasuryActAs($this, $member);
    $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $author->id, 'gross_amount' => 1000, 'payment_date' => '2026-06-15'])->assertCreated();
});

it('creates, shows, updates and deletes a recipient, deriving the jurisdiction from the regime', function (): void {
    $id = $this->postJson(WR_API.'/recipients', wrRecipientPayload('author_royalty'))->assertCreated()
        ->assertJsonPath('data.jurisdiction', 'IT')
        ->assertJsonPath('data.currency', 'EUR')
        ->assertJsonPath('data.cu_causal', 'B')
        ->json('data.id');

    $swiss = $this->postJson(WR_API.'/recipients', wrRecipientPayload('swiss_foreign_artist'))->assertCreated()
        ->assertJsonPath('data.jurisdiction', 'CH')
        ->assertJsonPath('data.currency', 'CHF')
        ->assertJsonPath('data.cu_causal', null)
        ->json('data.id');

    $this->getJson(WR_API.'/recipients')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson(WR_API."/recipients/{$id}")->assertOk()->assertJsonPath('data.display_name', 'Giulia Autrice')->assertJsonPath('data.certificates', [])->assertJsonPath('data.inl_notifications', []);

    $this->putJson(WR_API."/recipients/{$id}", ['display_name' => 'Giulia Rossi', 'cu_causal' => 'M1'])->assertOk()
        ->assertJsonPath('data.display_name', 'Giulia Rossi')
        ->assertJsonPath('data.cu_causal', 'M1');

    $this->deleteJson(WR_API."/recipients/{$swiss}")->assertOk();
    $this->getJson(WR_API."/recipients/{$swiss}")->assertNotFound();
});

it('validates the recipient and keeps the tax code unique inside the company', function (): void {
    $this->postJson(WR_API.'/recipients', [])->assertStatus(422)->assertJsonValidationErrors(['display_name', 'regime']);
    $this->postJson(WR_API.'/recipients', wrRecipientPayload('author_royalty', ['regime' => 'freelancer']))->assertStatus(422)->assertJsonValidationErrors(['regime']);
    $this->postJson(WR_API.'/recipients', wrRecipientPayload('author_royalty', ['birth_date' => '2999-01-01']))->assertStatus(422)->assertJsonValidationErrors(['birth_date']);
    $this->postJson(WR_API.'/recipients', wrRecipientPayload('author_royalty', ['cu_causal' => 'X']))->assertStatus(422)->assertJsonValidationErrors(['cu_causal']);
    $this->postJson(WR_API.'/recipients', wrRecipientPayload('occasional_worker', ['third_party_ytd_income' => 100]))->assertStatus(422)->assertJsonValidationErrors(['third_party_income_year']);

    wrRecipient($this, 'author_royalty');
    $this->postJson(WR_API.'/recipients', wrRecipientPayload('author_royalty', ['display_name' => 'Doppione']))->assertStatus(422)->assertJsonValidationErrors(['tax_code']);

    treasuryActor($this, 'accountant', $this->otherTenant);
    $this->postJson(WR_API.'/recipients', wrRecipientPayload('author_royalty'))->assertCreated();
});

it('does not leak recipients across companies', function (): void {
    $mine = wrRecipient($this, 'author_royalty');

    treasuryActor($this, 'accountant', $this->otherTenant);
    $this->getJson(WR_API.'/recipients')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(WR_API."/recipients/{$mine->id}")->assertNotFound();
    $this->putJson(WR_API."/recipients/{$mine->id}", ['display_name' => 'Hacked'])->assertNotFound();
    $this->deleteJson(WR_API."/recipients/{$mine->id}")->assertNotFound();
    $this->postJson(WR_API.'/compensations/simulate', ['recipient_profile_id' => $mine->id, 'gross_amount' => 100, 'payment_date' => '2026-06-15'])->assertStatus(422)->assertJsonValidationErrors(['recipient_profile_id']);
    $this->getJson(WR_API.'/dashboard')->assertOk()->assertJsonCount(0, 'data');
});

it('refuses to change the regime or delete a recipient once compensations were posted', function (): void {
    $author = wrRecipient($this, 'author_royalty');
    $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $author->id, 'gross_amount' => 1000, 'payment_date' => '2026-06-15'])->assertCreated();

    $this->putJson(WR_API."/recipients/{$author->id}", ['regime' => 'occasional_worker'])->assertStatus(422)->assertJsonPath('code', 'regime_locked');
    $this->putJson(WR_API."/recipients/{$author->id}", ['regime' => 'author_royalty', 'display_name' => 'Same regime'])->assertOk();
    $this->deleteJson(WR_API."/recipients/{$author->id}")->assertStatus(422)->assertJsonPath('code', 'recipient_has_settlements');
});

it('records the annual 20% declaration of an agent only, and a covering declaration lowers the withholding base', function (): void {
    $agent = wrRecipient($this, 'agent_25bis');
    $author = wrRecipient($this, 'author_royalty');
    $payload = ['fiscal_year' => 2026, 'received_on' => '2025-12-20', 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'pec_message_id' => '<abc@pec.example.com>', 'pec_archive_reference' => 'ARCH-2025-77'];

    $this->postJson(WR_API."/recipients/{$author->id}/certificates", $payload)->assertStatus(422)->assertJsonPath('code', 'certificate_not_applicable');
    $this->postJson(WR_API."/recipients/{$agent->id}/certificates", array_merge($payload, ['valid_to' => '2025-12-31']))->assertStatus(422)->assertJsonValidationErrors(['valid_to']);

    $simulation = ['recipient_profile_id' => $agent->id, 'gross_amount' => 10000, 'payment_date' => '2026-06-15'];
    $this->postJson(WR_API.'/compensations/simulate', $simulation)->assertOk()->assertJsonPath('data.irpef_withholding', '1150.00')->assertJsonPath('data.effective_withholding_rate', '11.50');

    $certId = $this->postJson(WR_API."/recipients/{$agent->id}/certificates", $payload)->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.received_in_time', true)
        ->assertJsonPath('data.pec_archive_reference', 'ARCH-2025-77')
        ->json('data.id');

    $this->postJson(WR_API.'/compensations/simulate', $simulation)->assertOk()->assertJsonPath('data.irpef_withholding', '460.00')->assertJsonPath('data.effective_withholding_rate', '4.60');
    $this->postJson(WR_API.'/compensations/simulate', ['payment_date' => '2027-01-20'] + $simulation)->assertOk()->assertJsonPath('data.irpef_withholding', '1150.00'); // after the validity

    $this->getJson(WR_API."/recipients/{$agent->id}/certificates")->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson(WR_API."/certificates/{$certId}")->assertOk();
    $this->postJson(WR_API.'/compensations/simulate', $simulation)->assertOk()->assertJsonPath('data.irpef_withholding', '1150.00');
});

it('simulates ad-hoc facts without a registered recipient', function (): void {
    $this->postJson(WR_API.'/compensations/simulate', ['regime' => 'author_royalty', 'gross_amount' => 1000, 'payment_date' => '2026-06-15', 'tax_code' => 'RSSMRA96L01H501X'])->assertOk()
        ->assertJsonPath('data.flat_rate_deduction', '400.00')
        ->assertJsonPath('data.net_payable', '880.00')
        ->assertJsonPath('data.cu_causal', 'B');

    $this->postJson(WR_API.'/compensations/simulate', ['regime' => 'occasional_worker', 'gross_amount' => 1000, 'payment_date' => '2026-06-15', 'third_party_income' => 4500])->assertOk()
        ->assertJsonPath('data.inps_base', '500.00')
        ->assertJsonPath('data.warnings.0', 'inps_threshold_exceeded')
        ->assertJsonPath('data.thresholds.0.key', 'occasional_5000');

    $this->postJson(WR_API.'/compensations/simulate', ['regime' => 'swiss_foreign_artist', 'gross_amount' => 4000, 'payment_date' => '2026-06-15', 'activity_type' => 'artist'])->assertOk()
        ->assertJsonPath('data.currency', 'CHF')
        ->assertJsonPath('data.source_tax', '200.00')
        ->assertJsonPath('data.effective_withholding_rate', '5.00');
});

it('validates the simulation', function (): void {
    $this->postJson(WR_API.'/compensations/simulate', [])->assertStatus(422)->assertJsonValidationErrors(['regime', 'gross_amount', 'payment_date']);
    $this->postJson(WR_API.'/compensations/simulate', ['regime' => 'author_royalty', 'gross_amount' => 0, 'payment_date' => '2026-06-15'])->assertStatus(422)->assertJsonValidationErrors(['gross_amount']);
    $this->postJson(WR_API.'/compensations/simulate', ['regime' => 'author_royalty', 'gross_amount' => 10, 'payment_date' => '2026-06-15', 'service_date' => '2026-07-01'])->assertStatus(422)->assertJsonValidationErrors(['service_date']);
});

it('uses the third-party income self-certified on the profile only in its own year', function (): void {
    $worker = wrRecipient($this, 'occasional_worker', ['third_party_ytd_income' => 4500, 'third_party_income_year' => 2026, 'third_party_declared_on' => '2026-05-10']);
    $body = ['recipient_profile_id' => $worker->id, 'gross_amount' => 1000, 'payment_date' => '2026-06-15'];

    $this->postJson(WR_API.'/compensations/simulate', $body)->assertOk()->assertJsonPath('data.inps_base', '500.00');
    $this->postJson(WR_API.'/compensations/simulate', ['payment_date' => '2027-02-15'] + $body)->assertOk()->assertJsonPath('data.inps_base', '0.00');
});

it('lists the lookups of the module', function (): void {
    $this->getJson(WR_API.'/lookups')->assertOk()
        ->assertJsonCount(6, 'data.regimes')
        ->assertJsonPath('data.parameters.occasional_inps_threshold', '5000')
        ->assertJsonPath('data.parameters.swiss_de_minimis', '2300')
        ->assertJsonFragment(['value' => 'swiss_foreign_artist', 'jurisdiction' => 'CH', 'currency' => 'CHF']);
});

it('builds the dashboard with year-to-date earnings, filters and compliance alerts', function (): void {
    $agent = wrRecipient($this, 'agent_25bis');
    $worker = wrRecipient($this, 'occasional_worker');
    $author = wrRecipient($this, 'author_royalty', ['birth_date' => null, 'tax_code' => 'INVALID']);
    $swiss = wrRecipient($this, 'swiss_de_minimis', ['avs_number' => '756.1111.2222.33']);

    wrInlSent($this, $worker);
    $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $worker->id, 'gross_amount' => 6000, 'payment_date' => '2026-06-15', 'service_date' => '2026-06-10'])->assertCreated();
    $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $swiss->id, 'gross_amount' => 3000, 'payment_date' => '2026-06-15'])->assertCreated();

    $rows = collect($this->getJson(WR_API.'/dashboard')->assertOk()
        ->assertJsonPath('meta.year', 2026)
        ->assertJsonPath('meta.recipients', 4)
        ->assertJsonPath('meta.ytd_gross_eur', '6000.00')
        ->assertJsonPath('meta.ytd_gross_chf', '3000.00')
        ->json('data'))->keyBy('id');

    expect($rows[$worker->id]['ytd_gross'])->toBe('6000.00')
        ->and($rows[$worker->id]['payments'])->toBe(1)
        ->and($rows[$worker->id]['alerts'])->toContain('occasional_threshold_reached')->not->toContain('inl_notification_missing')
        ->and($rows[$worker->id]['thresholds'][0]['key'])->toBe('occasional_5000')
        ->and($rows[$worker->id]['thresholds'][0]['percent'])->toEqual(100)
        ->and($rows[$agent->id]['alerts'])->toContain('agent_certificate_missing')
        ->and($rows[$author->id]['alerts'])->toContain('birth_date_missing')
        ->and($rows[$swiss->id]['alerts'])->toContain('avs_de_minimis_exceeded')
        ->and($rows[$swiss->id]['currency'])->toBe('CHF');

    $this->getJson(WR_API.'/dashboard?jurisdiction=CH')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $swiss->id);
    $this->getJson(WR_API.'/dashboard?regime=agent_25bis')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(WR_API.'/dashboard?cu_causal=Q')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $agent->id);
    $this->getJson(WR_API.'/dashboard?search=occasionale')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $worker->id);
    $this->getJson(WR_API.'/dashboard?only_alerts=1')->assertOk()->assertJsonCount(4, 'data');
    $this->getJson(WR_API.'/dashboard?year=2025')->assertOk()->assertJsonPath('meta.ytd_gross_eur', '0.00');
    $this->getJson(WR_API.'/dashboard?regime=nope')->assertStatus(422)->assertJsonValidationErrors(['regime']);
});

it('flags an expiring declaration and a missing INL communication', function (): void {
    $agent = wrRecipient($this, 'agent_25bis');
    $worker = wrRecipient($this, 'occasional_worker');
    $this->postJson(WR_API."/recipients/{$agent->id}/certificates", ['fiscal_year' => 2026, 'received_on' => '2025-12-20', 'valid_from' => '2026-01-01', 'valid_to' => '2026-07-31'])->assertCreated()->assertJsonPath('data.status', 'expiring');

    $rows = collect($this->getJson(WR_API.'/dashboard')->assertOk()->json('data'))->keyBy('id');

    expect($rows[$agent->id]['alerts'])->toContain('agent_certificate_expiring')->not->toContain('agent_certificate_missing')
        ->and($rows[$worker->id]['alerts'])->toContain('inl_notification_missing')
        ->and($this->getJson(WR_API.'/dashboard')->json('meta.pending_inl'))->toBe(1);
});

it('reports the threshold progress of a recipient per year', function (): void {
    $sports = wrRecipient($this, 'amateur_sports', ['third_party_ytd_income' => 7000, 'third_party_income_year' => 2026]);

    $this->getJson(WR_API."/recipients/{$sports->id}/thresholds")->assertOk()
        ->assertJsonPath('meta.year', 2026)
        ->assertJsonPath('data.0.key', 'sports_5000')
        ->assertJsonPath('data.0.percent', 100.0)
        ->assertJsonPath('data.1.key', 'sports_15000')
        ->assertJsonPath('data.1.current', '7000.00');

    $this->getJson(WR_API."/recipients/{$sports->id}/thresholds?year=2025")->assertOk()->assertJsonPath('data.1.current', '0.00');
});
