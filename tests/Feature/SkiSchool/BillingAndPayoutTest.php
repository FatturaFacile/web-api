<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\SkiOutdoorSchool\Models\CourseEnrollment;
use Modules\SkiOutdoorSchool\Models\InstructorCommission;
use Modules\SkiOutdoorSchool\Models\PayoutStatement;
use Modules\SkiOutdoorSchool\Models\SkiLesson;

require_once __DIR__.'/SkiSchoolSupport.php';

uses(RefreshDatabase::class);

/** A completed lesson of the instructor: minutes are slot_minutes x slot_count. */
function skCompletedLesson(object $test, string $instructorId, array $overrides = []): SkiLesson
{
    $lesson = skLesson($test, array_merge(['instructor_id' => $instructorId, 'auto_assign' => false], $overrides));
    $test->postJson(SK_API.'/lessons/'.$lesson->id.'/complete')->assertOk();

    return $lesson->refresh();
}

function skPackage(object $test, string $lessonId): void
{
    $test->putJson(SK_API.'/lessons/'.$lessonId.'/package', ['items' => [
        ['item_type' => 'RENTAL_SKI_KIT', 'quantity' => 1, 'days' => 1, 'unit_price_net' => 35],
        ['item_type' => 'SKIPASS', 'quantity' => 1, 'days' => 1, 'unit_price_net' => 55],
    ]])->assertOk();
}

// --- Invoicing with the IT / CH VAT exemption ----------------------------------------------------------------------------------------------

test('an Italian lesson is invoiced exempt with nature N4 and its taxable add-ons go on a second invoice', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    $lesson = skLesson($this, ['instructor_id' => $anna->id, 'auto_assign' => false, 'price_net' => 82.5]);
    skPackage($this, (string) $lesson->id);

    $result = $this->postJson(SK_API.'/lessons/'.$lesson->id.'/invoice', ['contact_id' => skCustomer($this)->id])->assertCreated()->json('data');

    $exempt = SalesInvoice::query()->findOrFail($result['invoice_id']);
    $addons = SalesInvoice::query()->findOrFail($result['addons_invoice_id']);
    expect($exempt->vat_nature)->toBe('N4')->and((float) $exempt->total_net)->toBe(82.5)->and((float) $exempt->total_tax)->toBe(0.0)
        ->and($exempt->items->first()->description)->toContain('Art. 10 n. 20 DPR 633/72')
        ->and($addons->vat_nature)->toBeNull()->and((float) $addons->total_net)->toBe(90.0)->and((float) $addons->total_tax)->toBe(13.2)
        ->and($result['totals'])->toBe(['net_cents' => 17250, 'vat_cents' => 1320, 'gross_cents' => 18570])
        ->and((string) $lesson->refresh()->invoice_id)->toBe((string) $exempt->id)->and((string) $lesson->addons_invoice_id)->toBe((string) $addons->id);
});

test('a lesson without add-ons produces a single exempt invoice', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    $lesson = skLesson($this, ['instructor_id' => $anna->id, 'auto_assign' => false]);

    $this->postJson(SK_API.'/lessons/'.$lesson->id.'/invoice', ['contact_id' => skCustomer($this)->id])->assertCreated()->assertJsonPath('data.addons_invoice_id', null);

    expect(SalesInvoice::query()->count())->toBe(1);
});

test('a lesson is invoiced once and its package is locked afterwards', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    $lesson = skLesson($this, ['instructor_id' => $anna->id, 'auto_assign' => false]);
    $customer = skCustomer($this);

    $this->postJson(SK_API.'/lessons/'.$lesson->id.'/invoice', ['contact_id' => $customer->id])->assertCreated();
    $this->postJson(SK_API.'/lessons/'.$lesson->id.'/invoice', ['contact_id' => $customer->id])->assertUnprocessable()->assertJsonPath('code', 'lesson_invoiced');
    $this->putJson(SK_API.'/lessons/'.$lesson->id.'/package', ['items' => []])->assertUnprocessable()->assertJsonPath('code', 'owner_invoiced');
    $this->postJson(SK_API.'/lessons/'.$lesson->id.'/cancel', [])->assertUnprocessable()->assertJsonPath('code', 'lesson_invoiced');
});

test('the customer must be chosen in the contacts to invoice and an unassigned lesson cannot be billed', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    $lesson = skLesson($this, ['instructor_id' => $anna->id, 'auto_assign' => false]);
    $request = skLesson($this, ['auto_assign' => false, 'start_time' => '14:00']);

    $this->postJson(SK_API.'/lessons/'.$lesson->id.'/invoice', [])->assertUnprocessable()->assertJsonPath('code', 'customer_contact_required');
    $this->postJson(SK_API.'/lessons/'.$request->id.'/invoice', ['contact_id' => skCustomer($this)->id])->assertUnprocessable()->assertJsonPath('code', 'lesson_not_billable');
});

test('a Swiss school invoices in francs on a single document that carries the LIVA exemption of the instruction', function (): void {
    skSetUp($this);
    $this->putJson(SK_API.'/settings', ['jurisdiction' => 'CH'])->assertOk()->assertJsonPath('data.default_vat_regime', 'CH_EXEMPT_ART21_N11');
    $anna = skInstructor($this);
    $lesson = skLesson($this, ['instructor_id' => $anna->id, 'auto_assign' => false, 'price_net' => 100]);
    skPackage($this, (string) $lesson->id);

    $result = $this->postJson(SK_API.'/lessons/'.$lesson->id.'/invoice', ['contact_id' => skCustomer($this)->id])->assertCreated()->assertJsonPath('data.addons_invoice_id', null)->json('data');

    $invoice = SalesInvoice::query()->findOrFail($result['invoice_id']);
    expect($lesson->refresh()->vat_regime->value)->toBe('CH_EXEMPT_ART21_N11')->and($invoice->currency)->toBe('CHF')->and($invoice->jurisdiction)->toBe('CH')
        ->and($invoice->items)->toHaveCount(3)->and($invoice->items->first()->description)->toContain('Art. 21 cpv. 2 n. 11 LIVA')
        ->and((float) $invoice->total_net)->toBe(190.0)->and((float) $invoice->total_tax)->toBeGreaterThan(4.9)->toBeLessThan(5.0);
});

test('a course enrolment is invoiced with its package', function (): void {
    skSetUp($this);
    $course = skCourse($this);
    $enrollment = skEnroll($this, $course, ['items' => [['item_type' => 'ARTVA_KIT', 'quantity' => 1, 'days' => 5, 'unit_price_net' => 5]]]);

    $result = $this->postJson(SK_API.'/enrollments/'.$enrollment.'/invoice', ['contact_id' => skCustomer($this)->id])->assertCreated()->json('data');

    expect(SalesInvoice::query()->findOrFail($result['invoice_id'])->vat_nature)->toBe('N4')->and((float) SalesInvoice::query()->findOrFail($result['addons_invoice_id'])->total_net)->toBe(25.0)
        ->and(CourseEnrollment::query()->findOrFail($enrollment)->invoice_id)->not->toBeNull();
    $this->deleteJson(SK_API.'/enrollments/'.$enrollment)->assertUnprocessable()->assertJsonPath('code', 'enrollment_invoiced');
});

// --- Payout statements ---------------------------------------------------------------------------------------------------------------------

test('the monthly summary and the generated statement total hours, gross fees, secretariat retention and net payable', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    skCompletedLesson($this, (string) $anna->id, ['slot_minutes' => 30, 'slot_count' => 2]);
    skCompletedLesson($this, (string) $anna->id, ['start_time' => '11:00', 'lesson_date' => '2027-01-20']);
    $course = skCourse($this, ['instructor_id' => $anna->id, 'daily_start_time' => '14:00', 'duration_minutes' => 60, 'end_date' => '2027-01-19']);
    $this->postJson(SK_API.'/courses/'.$course->id.'/status', ['status' => 'COMPLETED'])->assertOk();

    $summary = $this->getJson(SK_API.'/payouts/summary?month=2027-01')->assertOk()->assertJsonCount(1, 'data.instructors')->json('data.instructors.0');
    expect($summary['private_hours'])->toBe(1.92)->and($summary['group_hours'])->toBe(2.0)->and($summary['gross_total'])->toBe(156.67)->and($summary['retention_total'])->toBe(15.67)->and($summary['net_payable'])->toBe(141.0)
        ->and($summary['statement_status'])->toBeNull();

    $this->postJson(SK_API.'/payout-statements/generate', ['month' => '2027-01'])->assertCreated()
        ->assertJsonCount(1, 'data.statements')
        ->assertJsonPath('data.statements.0.status', 'DRAFT')
        ->assertJsonPath('data.statements.0.reference', 'EC-202701-001')
        ->assertJsonPath('data.statements.0.hours_total', '3.92')
        ->assertJsonPath('data.statements.0.gross_total', '156.67')
        ->assertJsonPath('data.statements.0.retention_total', '15.67')
        ->assertJsonPath('data.statements.0.net_payable', '141.00');

    expect(InstructorCommission::query()->whereNull('payout_statement_id')->count())->toBe(0);
});

test('the withholding of the collaboration regime is taken from the fee after the retention', function (): void {
    skSetUp($this);
    $anna = skInstructor($this, ['collaboration_regime' => 'OCCASIONAL', 'withholding_percent' => 20]);
    skCompletedLesson($this, (string) $anna->id, ['slot_minutes' => 30, 'slot_count' => 2]);
    skCompletedLesson($this, (string) $anna->id, ['start_time' => '11:00', 'lesson_date' => '2027-01-20']);

    $this->postJson(SK_API.'/payout-statements/generate', ['month' => '2027-01'])->assertCreated()
        ->assertJsonPath('data.statements.0.retention_total', '7.67')
        ->assertJsonPath('data.statements.0.withholding_percent', '20.00')
        ->assertJsonPath('data.statements.0.withholding_amount', '13.80')
        ->assertJsonPath('data.statements.0.net_payable', '55.20')
        ->assertJsonPath('data.statements.0.regime', 'OCCASIONAL');
});

test('generating again rebuilds the draft instead of duplicating it and a draft can be discarded', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    skCompletedLesson($this, (string) $anna->id);

    $first = (string) $this->postJson(SK_API.'/payout-statements/generate', ['month' => '2027-01'])->assertCreated()->json('data.statements.0.id');
    skCompletedLesson($this, (string) $anna->id, ['start_time' => '12:00']);
    $second = (string) $this->postJson(SK_API.'/payout-statements/generate', ['month' => '2027-01'])->assertCreated()->assertJsonPath('data.statements.0.gross_total', '73.34')->json('data.statements.0.id');

    expect($second)->toBe($first)->and(PayoutStatement::query()->count())->toBe(1);

    $this->deleteJson(SK_API.'/payout-statements/'.$first)->assertOk();
    expect(PayoutStatement::query()->count())->toBe(0)->and(InstructorCommission::query()->whereNull('payout_statement_id')->count())->toBe(2);
    $this->postJson(SK_API.'/payout-statements/generate', ['month' => '2027-01'])->assertCreated();
});

test('an issued statement is final: it is paid once and late fees are not added to it', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    skCompletedLesson($this, (string) $anna->id);
    $id = (string) $this->postJson(SK_API.'/payout-statements/generate', ['month' => '2027-01'])->json('data.statements.0.id');

    $this->postJson(SK_API.'/payout-statements/'.$id.'/pay')->assertUnprocessable()->assertJsonPath('code', 'invalid_statement_transition');
    $this->postJson(SK_API.'/payout-statements/'.$id.'/issue')->assertOk()->assertJsonPath('data.status', 'ISSUED');
    $this->deleteJson(SK_API.'/payout-statements/'.$id)->assertUnprocessable();

    skCompletedLesson($this, (string) $anna->id, ['start_time' => '12:00']);
    $this->postJson(SK_API.'/payout-statements/generate', ['month' => '2027-01'])->assertCreated()->assertJsonCount(0, 'data.statements')->assertJsonPath('data.skipped.0', (string) $anna->id);
    $this->postJson(SK_API.'/payout-statements/generate', ['month' => '2027-01', 'instructor_id' => $anna->id])->assertUnprocessable()->assertJsonPath('code', 'statement_locked');

    $this->postJson(SK_API.'/payout-statements/'.$id.'/pay')->assertOk()->assertJsonPath('data.status', 'PAID');
    $this->getJson(SK_API.'/payouts/summary?month=2027-01')->assertOk()->assertJsonPath('data.instructors.0.statement_status', 'PAID');
});

test('a month without commissions has nothing to pay and the month format is validated', function (): void {
    skSetUp($this);

    $this->postJson(SK_API.'/payout-statements/generate', ['month' => '2027-01'])->assertUnprocessable()->assertJsonPath('code', 'nothing_to_pay');
    $this->postJson(SK_API.'/payout-statements/generate', ['month' => '2027-13'])->assertUnprocessable()->assertJsonValidationErrors(['month']);
    $this->getJson(SK_API.'/payouts/summary')->assertUnprocessable()->assertJsonValidationErrors(['month']);
});

test('the statement is printed with the hours, the deductions, the net payable and the note of the regime', function (): void {
    skSetUp($this);
    $anna = skInstructor($this, ['collaboration_regime' => 'OCCASIONAL', 'withholding_percent' => 20]);
    skCompletedLesson($this, (string) $anna->id, ['slot_minutes' => 30, 'slot_count' => 2]);
    $id = (string) $this->postJson(SK_API.'/payout-statements/generate', ['month' => '2027-01'])->json('data.statements.0.id');

    $html = $this->get(SK_API.'/payout-statements/'.$id.'/pdf?format=html')->assertOk()->getContent();
    expect($html)->toContain('Instructor payout statement')->toContain('Anna Rossi')->toContain('EC-202701-001')->toContain('Secretariat retention')->toContain('28.80')->toContain('Occasional work')->toContain('Private lesson');

    $pdf = $this->get(SK_API.'/payout-statements/'.$id.'/pdf')->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')->and(str_starts_with((string) $pdf->getContent(), '%PDF'))->toBeTrue();
});

test('the payouts and statements of another company are not visible', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    skCompletedLesson($this, (string) $anna->id);
    $id = (string) $this->postJson(SK_API.'/payout-statements/generate', ['month' => '2027-01'])->json('data.statements.0.id');

    treasuryActAs($this, treasuryActor($this, 'accountant', $this->otherTenant), $this->otherTenant);

    $this->getJson(SK_API.'/payout-statements')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(SK_API.'/payout-statements/'.$id)->assertNotFound();
    $this->getJson(SK_API.'/payouts/summary?month=2027-01')->assertOk()->assertJsonCount(0, 'data.instructors');
});
