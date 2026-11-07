<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\Childcare\Mail\FamilyAccessMail;
use Modules\Childcare\Models\SubsidyRule;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-02 07:00:00');
    Storage::fake('private');
    $this->tenant = verticalTenant();
    $this->parent = verticalContact($this->tenant, 'Famiglia Bernasconi');
    $this->manager = verticalMember($this->tenant, 'childcare_manager');
    verticalApi($this->manager, $this->tenant);

    $this->enrol = function (string $firstName = 'Lucamarco', array $overrides = []): array {
        $child = $this->postJson('/api/v1/childcare/children', [
            'billing_contact_id' => $this->parent->id, 'first_name' => $firstName, 'last_name' => 'Bernasconi', 'birth_date' => '2023-04-12',
            'allergies' => 'Arachidi', 'enrolled_on' => '2026-09-01', ...$overrides,
        ])->assertCreated()->json('data');
        $mother = $this->postJson("/api/v1/childcare/children/{$child['id']}/reference-persons", [
            'full_name' => 'Anna Bernasconi', 'email' => 'anna@example.ch', 'relationship' => 'mother', 'is_guardian' => true, 'can_pick_up' => true, 'portal_contact_id' => $this->parent->id,
        ])->assertCreated()->json('data.id');
        $neighbour = $this->postJson("/api/v1/childcare/children/{$child['id']}/reference-persons", [
            'full_name' => 'Vicino', 'relationship' => 'other', 'is_guardian' => false, 'can_pick_up' => false,
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/childcare/children/{$child['id']}/weekly-plans", [
            'valid_from' => '2026-09-01', 'days' => ['mon' => ['from' => '08:00', 'to' => '17:00', 'meals' => ['lunch', 'snack']], 'tue' => ['from' => '08:00', 'to' => '12:00', 'meals' => ['lunch']]],
        ])->assertCreated();

        return ['id' => $child['id'], 'code' => $child['reference_code'], 'mother' => $mother, 'neighbour' => $neighbour];
    };
});

test('children data is encrypted at rest and never reaches the audit ledger', function (): void {
    $child = ($this->enrol)();

    $raw = DB::table('cc_children')->where('id', $child['id'])->first();
    expect($raw->first_name)->not->toBe('Lucamarco')->and($raw->allergies)->not->toContain('Arachidi');

    $this->getJson("/api/v1/childcare/children/{$child['id']}")->assertOk()->assertJsonPath('data.first_name', 'Lucamarco')->assertJsonPath('data.allergies', 'Arachidi');
    $this->putJson("/api/v1/childcare/children/{$child['id']}", ['health_notes' => 'Asma lieve'])->assertOk();

    $ledger = json_encode(DB::table('enterprise_audit_ledger')->get());
    expect($ledger)->not->toContain('Lucamarco')->not->toContain('Arachidi')->not->toContain('Asma')->not->toContain('cc_children');
});

test('the tablet checks children in and out, and only the management corrects with a kept history', function (): void {
    $child = ($this->enrol)();
    $educator = verticalMember($this->tenant, 'educator');
    verticalApi($educator, $this->tenant);

    $attendance = $this->postJson('/api/v1/childcare/attendances/check-in', ['child_id' => $child['id'], 'at' => '2026-11-02 08:05', 'temperature' => '36.8'])->assertOk()->json('data');
    expect(DB::table('cc_attendances')->value('temperature'))->not->toBe('36.8');
    $this->postJson('/api/v1/childcare/attendances/check-in', ['child_id' => $child['id'], 'at' => '2026-11-02 08:10'])->assertUnprocessable();
    $this->postJson('/api/v1/childcare/attendances/check-out', ['child_id' => $child['id'], 'at' => '2026-11-02 16:30', 'picked_up_by' => $child['neighbour']])->assertUnprocessable()
        ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'not authorised'));
    $this->postJson('/api/v1/childcare/attendances/check-out', ['child_id' => $child['id'], 'at' => '2026-11-02 16:30', 'picked_up_by' => $child['mother']])->assertOk();

    $this->getJson('/api/v1/childcare/attendances/register?month=2026-11')->assertOk()
        ->assertJsonPath('data.children.0.present_days', 1)->assertJsonPath('data.children.0.days.2026-11-02.check_out_at', '16:30');

    $this->putJson("/api/v1/childcare/attendances/{$attendance['id']}", ['check_out_at' => '2026-11-02 17:00', 'reason' => 'Orario errato'])->assertForbidden();

    verticalApi($this->manager, $this->tenant);
    $this->putJson("/api/v1/childcare/attendances/{$attendance['id']}", ['check_out_at' => '2026-11-02 07:00', 'reason' => 'Orario errato'])->assertUnprocessable();
    $this->putJson("/api/v1/childcare/attendances/{$attendance['id']}", ['check_out_at' => '2026-11-02 17:00', 'reason' => 'Orario errato'])->assertOk();
    $this->getJson("/api/v1/childcare/attendances/{$attendance['id']}/corrections")->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.field', 'check_out_at')->assertJsonPath('data.0.reason', 'Orario errato');
});

test('the kitchen gets the meal counts with allergies, staff hours add up', function (): void {
    $first = ($this->enrol)();
    $second = ($this->enrol)('Giulia', ['allergies' => null]);
    $this->postJson('/api/v1/childcare/attendances/absence', ['child_id' => $second['id'], 'date' => '2026-11-02', 'reason' => 'sick'])->assertOk();

    $meals = $this->getJson('/api/v1/childcare/meals?date=2026-11-02')->assertOk();
    expect($meals->json('data.meals'))->toMatchArray(['lunch' => 1, 'snack' => 1])
        ->and($meals->json('data.dietary_requirements.0'))->toMatchArray(['reference_code' => $first['code'], 'allergies' => 'Arachidi']);

    $educator = verticalMember($this->tenant, 'educator');
    verticalApi($educator, $this->tenant);
    $this->postJson('/api/v1/childcare/staff-shifts', ['user_id' => $educator->id, 'date' => '2026-11-02', 'starts_at' => '07:30', 'ends_at' => '16:00', 'break_minutes' => 30])->assertCreated();
    $this->postJson('/api/v1/childcare/staff-shifts', ['user_id' => $this->manager->id, 'date' => '2026-11-02', 'starts_at' => '07:30', 'ends_at' => '16:00'])->assertForbidden();
    $this->getJson('/api/v1/childcare/staff-hours?month=2026-11')->assertForbidden();

    verticalApi($this->manager, $this->tenant);
    $this->getJson('/api/v1/childcare/staff-hours?month=2026-11')->assertOk()->assertJsonPath('data.0.minutes', 480);
});

test('fees are billed monthly net of a subsidy that applies only once verified', function (): void {
    verticalLedger($this->tenant);
    verticalApi($this->manager, $this->tenant);
    $child = ($this->enrol)('Lucamarco', ['subsidy_canton' => 'TI', 'subsidy_inputs' => ['taxable_income' => 40000]]);
    $plan = $this->postJson('/api/v1/childcare/fee-plans', ['name' => 'Giornaliera', 'basis' => 'per_day', 'amount' => '100', 'meal_amount' => '10', 'tax_rate' => '0', 'currency' => 'CHF'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/childcare/children/{$child['id']}/fees", ['fee_plan_id' => $plan, 'valid_from' => '2026-09-01'])->assertCreated();
    $this->postJson('/api/v1/childcare/subsidy-rules', ['canton' => 'TI', 'name' => 'Prova', 'method' => 'income_brackets_per_day', 'parameters' => ['input' => 'taxable_income'], 'valid_from' => '2026-01-01'])->assertUnprocessable();
    $rule = $this->postJson('/api/v1/childcare/subsidy-rules', [
        'canton' => 'TI', 'name' => 'Regola di prova', 'method' => 'income_brackets_per_day', 'valid_from' => '2026-01-01',
        'parameters' => ['input' => 'taxable_income', 'brackets' => [['up_to' => '50000', 'amount_per_day' => '30'], ['up_to' => null, 'amount_per_day' => '0']]],
    ])->assertCreated()->json('data.id');

    foreach (['2026-11-02', '2026-11-03'] as $day) {
        $this->postJson('/api/v1/childcare/attendances/check-in', ['child_id' => $child['id'], 'at' => "{$day} 08:00"])->assertOk();
    }

    $this->postJson('/api/v1/childcare/billing/runs', ['period' => '2026-11', 'date' => '2026-11-30'])->assertOk()
        ->assertJsonCount(0, 'data.billed')->assertJsonPath('data.skipped.0.reason', fn (string $reason): bool => str_contains($reason, 'not verified'));

    $this->postJson("/api/v1/childcare/subsidy-rules/{$rule}/verify", ['source_reference' => '=Test fixture, not an official rule'])->assertOk();
    $run = $this->postJson('/api/v1/childcare/billing/runs', ['period' => '2026-11', 'date' => '2026-11-30'])->assertOk()->assertJsonPath('data.billed.0.net_amount', '170.00');

    $invoice = Invoice::query()->with('items')->findOrFail($run->json('data.billed.0.invoice_id'));
    expect((float) $invoice->total_gross)->toBe(170.0)
        ->and($invoice->currency)->toBe('CHF')
        ->and((string) $invoice->contact_id)->toBe((string) $this->parent->id)
        ->and(json_encode($invoice->items->pluck('description')))->toContain($child['code'])->not->toContain('Lucamarco');

    $this->postJson('/api/v1/childcare/billing/runs', ['period' => '2026-11', 'date' => '2026-11-30'])->assertOk()->assertJsonCount(0, 'data.billed');
    $csv = (string) $this->get('/api/v1/childcare/billing/subsidies/export?period=2026-11&canton=TI')->assertOk()->getContent();
    // The source reference starts with a formula character: the CSV neutralises it.
    expect($csv)->toContain($child['code'])->toContain('60.00')->toContain("'=Test fixture")->not->toContain(';=Test fixture');

    app(TenantContext::class)->setTenant($this->tenant);
    $model = SubsidyRule::query()->findOrFail($rule);
    $model->update(['parameters' => ['input' => 'taxable_income', 'brackets' => [['up_to' => null, 'amount_per_day' => '40']]]]);
    expect($model->refresh()->isVerified())->toBeFalse();

    verticalApi(verticalMember($this->tenant, 'accountant'), $this->tenant);
    $this->getJson('/api/v1/childcare/billing/bills?period=2026-11')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/childcare/children')->assertForbidden();
    $this->get('/api/v1/childcare/billing/subsidies/export?period=2026-11')->assertForbidden();
});

test('guardians see their own child, sign forms and exchange messages on the family portal', function (): void {
    Mail::fake();
    $child = ($this->enrol)();
    $other = ($this->enrol)('Altro');
    DB::table('cc_reference_persons')->where('child_id', $other['id'])->update(['portal_contact_id' => null]);

    $this->postJson("/api/v1/childcare/reference-persons/{$child['neighbour']}/portal-access")->assertUnprocessable();
    $this->postJson("/api/v1/childcare/reference-persons/{$child['mother']}/portal-access")->assertAccepted();
    $token = null;
    Mail::assertSent(FamilyAccessMail::class, function (FamilyAccessMail $mail) use (&$token): bool {
        $token = $mail->token;

        return true;
    });

    $this->postJson('/api/v1/childcare/notes', ['child_id' => $child['id'], 'date' => '2026-11-02', 'category' => 'meal', 'body' => 'Ha mangiato tutto', 'visible_to_family' => true])->assertCreated();
    $this->postJson('/api/v1/childcare/notes', ['child_id' => $child['id'], 'date' => '2026-11-02', 'category' => 'behaviour', 'body' => 'Nota interna'])->assertCreated();
    $requirement = $this->postJson('/api/v1/childcare/document-requirements', ['name' => 'Consenso foto', 'requires_signature' => true])->assertCreated()->json('data.id');
    $document = $this->postJson("/api/v1/childcare/children/{$child['id']}/documents", ['title' => 'Consenso foto', 'requirement_id' => $requirement])->assertCreated()->json('data.id');
    $this->getJson('/api/v1/childcare/documents/outstanding')->assertOk()->assertJsonPath('data.total_missing', 2);
    $this->postJson("/api/v1/childcare/children/{$child['id']}/messages", ['body' => 'Domani gita'])->assertCreated();

    test()->flushHeaders();
    app('auth')->forgetGuards();
    $family = fn () => $this->withHeader('X-Family-Token', (string) $token);

    $this->getJson('/api/v1/childcare-family/children')->assertUnauthorized();
    $family()->getJson('/api/v1/childcare-family/children')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.first_name', 'Lucamarco');
    $family()->getJson("/api/v1/childcare-family/children/{$other['id']}/daily-report?date=2026-11-02")->assertNotFound();
    $report = $family()->getJson("/api/v1/childcare-family/children/{$child['id']}/daily-report?date=2026-11-02")->assertOk();
    expect(json_encode($report->json('data.notes')))->toContain('Ha mangiato tutto')->not->toContain('Nota interna');

    $family()->postJson("/api/v1/childcare-family/documents/{$document}/sign", ['signed_name' => 'Anna Bernasconi'])->assertUnprocessable();
    $family()->postJson("/api/v1/childcare-family/documents/{$document}/sign", ['signed_name' => 'Anna Bernasconi', 'accept' => true])->assertOk()->assertJsonPath('data.status', 'signed');
    $family()->getJson('/api/v1/childcare-family/messages')->assertOk()->assertJsonPath('data.0.body', 'Domani gita');
    $family()->postJson('/api/v1/childcare-family/messages', ['child_id' => $other['id'], 'body' => 'x'])->assertNotFound();
    $family()->postJson('/api/v1/childcare-family/messages', ['child_id' => $child['id'], 'body' => 'Grazie'])->assertCreated();
    $family()->getJson('/api/v1/childcare-family/invoices')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/portal/invoices/{$token}")->assertNotFound();

    verticalApi($this->manager, $this->tenant);
    $this->getJson('/api/v1/childcare/documents/outstanding')->assertOk()->assertJsonPath('data.total_missing', 1);
    $this->getJson("/api/v1/childcare/children/{$child['id']}/messages")->assertOk()->assertJsonCount(2, 'data');
});

test('other companies and other roles stay out of the children data', function (): void {
    $child = ($this->enrol)();

    $other = verticalTenant();
    verticalApi(verticalMember($other, 'admin'), $other);
    $this->getJson("/api/v1/childcare/children/{$child['id']}")->assertNotFound();
    $this->getJson('/api/v1/childcare/children')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/childcare/attendances/check-in', ['child_id' => $child['id']])->assertUnprocessable()->assertJsonValidationErrors('child_id');

    foreach (['member', 'accountant_readonly', 'technician'] as $role) {
        verticalApi(verticalMember($this->tenant, $role), $this->tenant);
        $this->getJson('/api/v1/childcare/children')->assertForbidden();
    }

    verticalApi(verticalMember($this->tenant, 'educator'), $this->tenant);
    $this->getJson('/api/v1/childcare/children')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/childcare/children', ['billing_contact_id' => $this->parent->id, 'first_name' => 'X', 'last_name' => 'Y', 'enrolled_on' => '2026-11-01'])->assertForbidden();
    $this->getJson('/api/v1/childcare/documents/outstanding')->assertForbidden();
    $this->postJson('/api/v1/childcare/subsidy-rules', ['canton' => 'TI', 'name' => 'x', 'method' => 'fixed_per_day', 'parameters' => ['amount_per_day' => '1'], 'valid_from' => '2026-01-01'])->assertForbidden();
});
