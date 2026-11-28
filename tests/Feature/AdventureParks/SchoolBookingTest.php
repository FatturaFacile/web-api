<?php

declare(strict_types=1);

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Invoicing\Models\SalesInvoice;

require_once __DIR__.'/AdventureParksSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    apSetUp($this);
    $this->workshops = collect($this->getJson(AP_API.'/workshops')->assertOk()->json('data'))->pluck('id', 'code');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apSchoolPayload(object $test, array $overrides = []): array
{
    return array_merge([
        'institute_name' => 'IC Giovanni Pascoli',
        'mechanographic_code' => 'MIIC8A1005',
        'ipa_office_code' => 'UFABCD',
        'cig_code' => 'Z1234567AB',
        'cup_code' => 'B12C34567890001',
        'split_payment' => true,
        'visit_date' => '2026-09-24',
        'start_time' => '09:30',
        'end_time' => '13:00',
        'workshop_ids' => [$test->workshops['BREAD_GRAIN']],
        'picnic_reserved' => true,
        'paying_students' => 30,
        'disabled_students' => 1,
        'accompanying_teachers' => 4,
    ], $overrides);
}

it('ships the standard workshop catalogue with its names in every language', function (): void {
    expect($this->workshops->keys()->sort()->values()->all())->toBe(['BEES', 'BREAD_GRAIN', 'GARDEN', 'JUNIOR_ADVENTURE']);

    $bread = collect($this->getJson(AP_API.'/workshops')->json('data'))->firstWhere('code', 'BREAD_GRAIN');
    expect($bread['name_translations'])->toEqual(['it' => 'Dal grano al pane', 'en' => 'From grain to bread', 'de' => 'Vom Korn zum Brot', 'fr' => 'Du grain au pain'])
        ->and($bread['price_per_student'])->toBe(8.0)
        ->and($bread['is_standard'])->toBeTrue();

});

it('quotes a trip live: free teachers one every 15 paying students plus one per disabled student, picnic seats and total', function (): void {
    $query = http_build_query(['paying_students' => 30, 'disabled_students' => 1, 'accompanying_teachers' => 4, 'workshop_ids' => [$this->workshops['BREAD_GRAIN']]]);

    $this->getJson(AP_API.'/school-bookings/quote?'.$query)->assertOk()
        ->assertJsonPath('data.free_teachers', 3)
        ->assertJsonPath('data.charged_teachers', 1)
        ->assertJsonPath('data.picnic_seats', 35)
        ->assertJsonPath('data.total_amount', '248.00');

    $this->getJson(AP_API.'/school-bookings/quote?paying_students=10&accompanying_teachers=2')->assertOk()
        ->assertJsonPath('data.free_teachers', 0)
        ->assertJsonPath('data.charged_teachers', 2)
        ->assertJsonPath('data.total_amount', '0.00');

    $this->getJson(AP_API.'/school-bookings/quote?paying_students=10&workshop_ids[]='.(string) Str::uuid())->assertStatus(422)->assertJsonPath('code', 'workshop_not_found');
});

it('creates a school booking with the quote stored, requires the CIG for split payment and checks the picnic capacity', function (): void {
    $id = $this->postJson(AP_API.'/school-bookings', apSchoolPayload($this))->assertCreated()
        ->assertJsonPath('data.status', 'DRAFT')
        ->assertJsonPath('data.free_teachers', 3)
        ->assertJsonPath('data.charged_teachers', 1)
        ->assertJsonPath('data.picnic_seats', 35)
        ->assertJsonPath('data.total_amount', 248.0)
        ->assertJsonPath('data.split_payment', true)
        ->assertJsonPath('data.workshops.0.code', 'BREAD_GRAIN')
        ->assertJsonPath('data.workshops.0.quantity_students', 30)
        ->json('data.id');

    $withoutCig = apSchoolPayload($this, ['cig_code' => null]);
    $this->postJson(AP_API.'/school-bookings', $withoutCig)->assertStatus(422)->assertJsonValidationErrors(['cig_code']);
    $this->postJson(AP_API.'/school-bookings', apSchoolPayload($this, ['cig_code' => null, 'split_payment' => false, 'picnic_reserved' => false]))->assertCreated()->assertJsonPath('data.split_payment', false);

    // 35 seats are taken on 24 September: another 31 do not fit in the 60 of the picnic area
    $this->postJson(AP_API.'/school-bookings', apSchoolPayload($this, ['institute_name' => 'Liceo Parini', 'disabled_students' => 0, 'accompanying_teachers' => 1]))->assertStatus(422)
        ->assertJsonPath('code', 'picnic_capacity_exceeded')->assertJsonPath('context.capacity', 60)->assertJsonPath('context.taken', 35)->assertJsonPath('context.requested', 31);
    $this->postJson(AP_API.'/school-bookings', apSchoolPayload($this, ['institute_name' => 'Liceo Parini', 'disabled_students' => 0, 'accompanying_teachers' => 1, 'visit_date' => '2026-09-25']))->assertCreated();

    $this->putJson(AP_API.'/school-bookings/'.$id, apSchoolPayload($this, ['paying_students' => 15, 'disabled_students' => 0, 'accompanying_teachers' => 2]))->assertOk()
        ->assertJsonPath('data.free_teachers', 1)->assertJsonPath('data.charged_teachers', 1)->assertJsonPath('data.total_amount', 128.0);

    $this->getJson(AP_API.'/school-bookings?status=DRAFT')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson(AP_API.'/school-bookings/'.$id)->assertOk()->assertJsonPath('data.paying_students', 15);
});

it('invoices a confirmed school booking through the invoicing engine with split payment, CIG and CUP', function (): void {
    $id = $this->postJson(AP_API.'/school-bookings', apSchoolPayload($this))->assertCreated()->json('data.id');

    $this->postJson(AP_API.'/school-bookings/'.$id.'/generate-fattura-pa')->assertStatus(422)->assertJsonPath('code', 'booking_not_confirmed');
    $this->postJson(AP_API.'/school-bookings/'.$id.'/complete')->assertStatus(422)->assertJsonPath('code', 'invalid_transition');
    $this->postJson(AP_API.'/school-bookings/'.$id.'/confirm')->assertOk()->assertJsonPath('data.status', 'CONFIRMED');
    $this->postJson(AP_API.'/school-bookings/'.$id.'/confirm')->assertStatus(422)->assertJsonPath('code', 'invalid_transition');
    $this->postJson(AP_API.'/school-bookings/'.$id.'/complete')->assertOk()->assertJsonPath('data.status', 'COMPLETED');

    $response = $this->postJson(AP_API.'/school-bookings/'.$id.'/generate-fattura-pa')->assertOk()
        ->assertJsonPath('data.status', 'INVOICED')
        ->assertJsonPath('data.fattura_invoice_id', fn (string $value): bool => $value !== '');

    $invoice = SalesInvoice::withoutGlobalScopes()->findOrFail($response->json('data.fattura_invoice_id'));
    $extra = json_decode((string) $invoice->getRawOriginal('sdi_extra'), true);
    $order = $extra['FatturaElettronicaBody']['DatiGenerali']['DatiOrdineAcquisto'][0];
    $contact = Contact::withoutGlobalScopes()->findOrFail($invoice->contact_id);

    expect((bool) $invoice->split_payment)->toBeTrue()
        ->and($invoice->tenant_id)->toBe($this->tenant->id)
        ->and((string) ($invoice->status->value ?? $invoice->status))->toBe('draft')
        ->and($order['CodiceCIG'])->toBe('Z1234567AB')
        ->and($order['CodiceCUP'])->toBe('B12C34567890001')
        ->and($order['IdDocumento'])->toBe('MIIC8A1005')
        ->and($contact->sdi_code)->toBe('UFABCD')
        ->and($contact->company_name)->toBe('IC Giovanni Pascoli')
        // 31 heads (30 students and 1 charged teacher) x 8.00 at 10% VAT, plus the free teachers at zero
        ->and((float) $invoice->total_net)->toBe(248.0)
        ->and((float) $invoice->total_tax)->toBe(24.8)
        ->and($invoice->items()->count())->toBe(2);

    $this->getJson(AP_API.'/school-bookings/'.$id)->assertOk()->assertJsonPath('data.fattura_number', $invoice->invoice_number)->assertJsonPath('data.customer_contact_id', $contact->id);

    $this->postJson(AP_API.'/school-bookings/'.$id.'/generate-fattura-pa')->assertStatus(422)->assertJsonPath('code', 'already_invoiced');
    $this->putJson(AP_API.'/school-bookings/'.$id, apSchoolPayload($this))->assertStatus(422)->assertJsonPath('code', 'already_invoiced');
    $this->deleteJson(AP_API.'/school-bookings/'.$id)->assertStatus(422)->assertJsonPath('code', 'booking_locked');
});

it('keeps the invoice of a school trip to finance and the booking to the managers', function (): void {
    $id = $this->postJson(AP_API.'/school-bookings', apSchoolPayload($this))->assertCreated()->json('data.id');
    $this->postJson(AP_API.'/school-bookings/'.$id.'/confirm')->assertOk();

    billingActor($this, 'manager');
    $this->getJson(AP_API.'/school-bookings')->assertOk();
    $this->postJson(AP_API.'/school-bookings/'.$id.'/generate-fattura-pa')->assertForbidden();

    billingActor($this, 'operator');
    $this->postJson(AP_API.'/school-bookings', apSchoolPayload($this))->assertForbidden();
    $this->postJson(AP_API.'/school-bookings/'.$id.'/complete')->assertForbidden();

    billingActor($this, 'accountant');
    $this->postJson(AP_API.'/school-bookings/'.$id.'/generate-fattura-pa')->assertOk();
});

it('keeps the school bookings and the workshops of a company away from the others', function (): void {
    $id = $this->postJson(AP_API.'/school-bookings', apSchoolPayload($this))->assertCreated()->json('data.id');
    $own = $this->postJson(AP_API.'/workshops', ['code' => 'POTTERY', 'duration_minutes' => 60, 'max_participants' => 15, 'price_per_student' => 9])->assertCreated()->json('data.id');

    billingActor($this, 'accountant', $this->otherTenant);

    $this->getJson(AP_API.'/school-bookings')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(AP_API.'/school-bookings/'.$id)->assertNotFound();
    expect(collect($this->getJson(AP_API.'/workshops')->json('data'))->pluck('code')->all())->not->toContain('POTTERY');
    $this->putJson(AP_API.'/workshops/'.$own, ['price_per_student' => 1])->assertNotFound();
});

it('lets a company override a standard workshop with its own price and keeps its own workshops safe', function (): void {
    $standard = $this->workshops['BEES'];

    $copy = $this->putJson(AP_API.'/workshops/'.$standard, ['price_per_student' => 9.5])->assertOk()
        ->assertJsonPath('data.price_per_student', 9.5)
        ->assertJsonPath('data.is_standard', false)
        ->assertJsonPath('data.code', 'BEES')
        ->json('data');

    expect($copy['id'])->not->toBe($standard)->and($copy['name_translations']['de'])->toBe('Die Welt der Bienen');

    $list = collect($this->getJson(AP_API.'/workshops')->json('data'));
    expect($list)->toHaveCount(4)->and($list->firstWhere('code', 'BEES')['id'])->toBe($copy['id']);

    $this->deleteJson(AP_API.'/workshops/'.$standard)->assertStatus(422)->assertJsonPath('code', 'workshop_global');
    $this->postJson(AP_API.'/workshops', ['code' => 'bees', 'duration_minutes' => 60, 'max_participants' => 10, 'price_per_student' => 5])->assertStatus(422)->assertJsonPath('code', 'workshop_code_taken');

    $custom = $this->postJson(AP_API.'/workshops', ['code' => 'pottery', 'duration_minutes' => 60, 'max_participants' => 15, 'price_per_student' => 9, 'name_translations' => ['it' => 'Ceramica']])->assertCreated()
        ->assertJsonPath('data.code', 'POTTERY')->json('data.id');

    $this->postJson(AP_API.'/school-bookings', apSchoolPayload($this, ['workshop_ids' => [$custom]]))->assertCreated();
    $this->deleteJson(AP_API.'/workshops/'.$custom)->assertStatus(422)->assertJsonPath('code', 'workshop_in_use');
});
