<?php

declare(strict_types=1);

use App\Contracts\PdfGenerator;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Associations\Enums\FeeFrequency;
use Modules\Associations\Enums\InstalmentStatus;
use Modules\Associations\Gateways\LocalFeePaymentGateway;
use Modules\Associations\Mail\AssociationNoticeMail;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\Family;
use Modules\Associations\Models\FamilyDiscount;
use Modules\Associations\Models\FeeAssignment;
use Modules\Associations\Models\FeeInstalment;
use Modules\Associations\Models\FeePayment;
use Modules\Associations\Models\FeePlan;
use Modules\Associations\Models\Guardianship;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\Receipt;
use Modules\Associations\Services\FeeService;
use Modules\Associations\Services\FeeStandingService;
use Modules\Associations\Services\ReminderService;
use Modules\Associations\Support\Money;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\PaymentLedgerService;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    actAs($this, 'secretary');
    Mail::fake();
});

afterEach(fn () => associationsTearDown());

function feePlan(FeeFrequency $frequency, string $amount = '30.00', array $attributes = []): FeePlan
{
    return FeePlan::factory()->create(['frequency' => $frequency, 'amount' => $amount, ...$attributes]);
}

function assignFee(Member $member, FeePlan $plan, array $options = []): FeeAssignment
{
    return app(FeeService::class)->assign($member, $plan, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', ...$options]);
}

test('every frequency builds the right calendar', function (FeeFrequency $frequency, int $instalments, string $total): void {
    $assignment = assignFee(Member::factory()->create(), feePlan($frequency, '30.00'));

    expect($assignment->instalments)->toHaveCount($instalments)
        ->and(Money::normalize((string) $assignment->net_amount))->toBe($total);
})->with([
    'one off' => [FeeFrequency::OneOff, 1, '30.00'],
    'monthly' => [FeeFrequency::Monthly, 12, '360.00'],
    'bimonthly' => [FeeFrequency::Bimonthly, 6, '180.00'],
    'quarterly' => [FeeFrequency::Quarterly, 4, '120.00'],
    'four-monthly' => [FeeFrequency::FourMonthly, 3, '90.00'],
    'half-yearly' => [FeeFrequency::HalfYearly, 2, '60.00'],
    'annual' => [FeeFrequency::Annual, 1, '30.00'],
]);

test('due dates do not overflow past the end of a short month', function (): void {
    $assignment = assignFee(Member::factory()->create(), feePlan(FeeFrequency::Monthly), ['starts_on' => '2026-01-31', 'ends_on' => '2026-03-31']);

    expect($assignment->instalments->map(fn (FeeInstalment $i): string => $i->due_on->toDateString())->all())->toBe(['2026-01-31', '2026-02-28', '2026-03-31']);
});

test('a total in instalments adds up to the cent', function (): void {
    $assignment = assignFee(Member::factory()->create(), feePlan(FeeFrequency::OneOff, '100.00', ['instalments' => 3]));

    expect($assignment->instalments->pluck('amount')->map(fn ($amount): string => (string) $amount)->all())->toBe(['33.33', '33.33', '33.34'])
        ->and(Money::normalize((string) $assignment->net_amount))->toBe('100.00')
        ->and($assignment->instalments[1]->due_on->toDateString())->toBe('2026-02-01');
});

test('the family discount starts from the configured position', function (): void {
    FamilyDiscount::query()->create(['name' => 'Second child', 'from_position' => 2, 'percentage' => '10.00']);
    FamilyDiscount::query()->create(['name' => 'Third child', 'from_position' => 3, 'percentage' => '25.00']);
    $family = Family::query()->create(['name' => 'Bianchi']);
    $plan = feePlan(FeeFrequency::OneOff, '99.99');
    [$first, $second, $third] = Member::factory()->count(3)->create(['family_id' => $family->id]);

    $amounts = [assignFee($first, $plan), assignFee($second, $plan), assignFee($third, $plan)];

    expect(array_map(fn (FeeAssignment $a): string => (string) $a->net_amount, $amounts))->toBe(['99.99', '89.99', '74.99'])
        ->and((string) $amounts[1]->discount_amount)->toBe('10.00');
});

test('a minor pays through the guardian', function (): void {
    $minor = Member::factory()->minor()->create();
    $guardian = Contact::create(['first_name' => 'Paola', 'last_name' => 'Neri', 'email' => 'paola@example.com']);
    Guardianship::query()->create(['minor_member_id' => $minor->id, 'guardian_contact_id' => $guardian->id, 'is_primary' => true]);

    expect(assignFee($minor, feePlan(FeeFrequency::OneOff))->payer_contact_id)->toBe($guardian->id);
});

test('the fee state of a participant follows what was paid', function (): void {
    AssociationSetting::current()->update(['ledger_enabled' => false]);
    $member = Member::factory()->create();
    $standing = app(FeeStandingService::class);

    expect($standing->of($member)->color())->toBe('orange');

    $assignment = assignFee($member, feePlan(FeeFrequency::OneOff, '50.00'), ['starts_on' => now()->addDay()->toDateString(), 'ends_on' => now()->addMonth()->toDateString()]);
    $instalment = $assignment->instalments->first();

    expect($standing->of($member)->color())->toBe('green');

    $this->postJson("/api/v1/associations/instalments/{$instalment->id}/payments", ['amount' => '20.00', 'paid_on' => now()->toDateString(), 'method' => 'cash'])->assertCreated();
    expect($standing->of($member)->color())->toBe('orange');

    $instalment->update(['due_on' => now()->subDay()]);
    expect($standing->of($member)->color())->toBe('red');

    $this->getJson("/api/v1/associations/members/{$member->id}/fee-standing")->assertOk()->assertJsonPath('data.color', 'red');

    $this->postJson("/api/v1/associations/instalments/{$instalment->id}/payments", ['amount' => '30.00', 'paid_on' => now()->toDateString(), 'method' => 'cash'])->assertCreated();
    expect($standing->of($member)->color())->toBe('green');
});

test('a payment goes to the accounts through the existing flow, and posts each movement once', function (): void {
    openFiscalPeriod($this->tenant);
    $member = Member::factory()->create();
    $instalment = assignFee($member, feePlan(FeeFrequency::OneOff, '80.00'))->instalments->first();

    $id = $this->postJson("/api/v1/associations/instalments/{$instalment->id}/payments", ['amount' => '80.00', 'paid_on' => now()->toDateString(), 'method' => 'bank_transfer', 'reference' => 'CRO123'])
        ->assertCreated()->json('data.id');

    $payment = FeePayment::query()->findOrFail($id);
    $invoice = Invoice::query()->findOrFail($payment->invoice_id);
    $corePayment = Payment::query()->findOrFail($payment->payment_id);
    $entries = JournalEntry::query()->where('tenant_id', $this->tenant->id)->get();

    expect($invoice->status)->toBe('posted')
        ->and($invoice->payment_status)->toBe('paid')
        ->and($invoice->invoice_number)->toBe($payment->receipt->number)
        ->and($invoice->contact_id)->toBe($member->contact_id)
        ->and((string) $corePayment->amount)->toBe('80.00')
        ->and($corePayment->payment_method)->toBe('MP05')
        ->and($corePayment->invoice_id)->toBe($invoice->id)
        ->and($entries->pluck('source_document_type')->sort()->values()->all())->toBe([PaymentLedgerService::SOURCE_CUSTOMER, 'sales_invoice'])
        ->and(DB::table('document_sequences')->where('tenant_id', $this->tenant->id)->where('document_type', 'invoice')->exists())->toBeFalse();
});

test('a part payment and the balance each make their own receipt', function (): void {
    openFiscalPeriod($this->tenant);
    $instalment = assignFee(Member::factory()->create(), feePlan(FeeFrequency::OneOff, '80.00'))->instalments->first();
    $pay = fn (string $amount) => $this->postJson("/api/v1/associations/instalments/{$instalment->id}/payments", ['amount' => $amount, 'paid_on' => now()->toDateString(), 'method' => 'cash']);

    $pay('30.00')->assertCreated();
    expect($instalment->refresh()->status)->toBe(InstalmentStatus::Partial);

    $pay('60.00')->assertUnprocessable();
    $pay('50.00')->assertCreated();
    $pay('1.00')->assertConflict();

    $numbers = Receipt::query()->orderBy('sequential_number')->pluck('sequential_number')->all();

    expect($instalment->refresh()->status)->toBe(InstalmentStatus::Paid)
        ->and((string) $instalment->paid_amount)->toBe('80.00')
        ->and($numbers)->toBe([1, 2]);
});

test('without an open fiscal period nothing is half recorded, and the receipt number goes back', function (): void {
    $instalment = assignFee(Member::factory()->create(), feePlan(FeeFrequency::OneOff, '80.00'))->instalments->first();

    $this->postJson("/api/v1/associations/instalments/{$instalment->id}/payments", ['amount' => '80.00', 'paid_on' => now()->toDateString(), 'method' => 'cash'])->assertStatus(422);

    expect(FeePayment::query()->count())->toBe(0)
        ->and(Receipt::query()->count())->toBe(0)
        ->and(Invoice::query()->count())->toBe(0)
        ->and((float) $instalment->refresh()->paid_amount)->toBe(0.0);

    openFiscalPeriod($this->tenant);

    $this->postJson("/api/v1/associations/instalments/{$instalment->id}/payments", ['amount' => '80.00', 'paid_on' => now()->toDateString(), 'method' => 'cash'])->assertCreated();

    expect(Receipt::query()->firstOrFail()->sequential_number)->toBe(1);
});

test('receipts come in two forms, numbered per tenant, with a frozen copy of the holder', function (): void {
    AssociationSetting::current()->update(['ledger_enabled' => false, 'receipt_prefix' => 'R-']);
    $member = Member::factory()->create(['tax_code' => 'RSSMRA85M01H501Q']);
    $instalments = assignFee($member, feePlan(FeeFrequency::HalfYearly, '10.00'))->instalments;
    $year = now()->year;

    $this->postJson("/api/v1/associations/instalments/{$instalments[0]->id}/payments", ['amount' => '10.00', 'paid_on' => now()->toDateString(), 'method' => 'cash', 'receipt_kind' => 'simple'])->assertCreated();
    $this->postJson("/api/v1/associations/instalments/{$instalments[1]->id}/payments", ['amount' => '10.00', 'paid_on' => now()->toDateString(), 'method' => 'cash', 'receipt_kind' => 'full'])->assertCreated();

    [$simple, $full] = Receipt::query()->orderBy('sequential_number')->get()->all();

    expect($simple->number)->toBe("R-{$year}-0001")
        ->and($full->number)->toBe("R-{$year}-0002")
        ->and($simple->holder)->not->toHaveKey('tax_code')
        ->and($full->holder['tax_code'])->toBe('RSSMRA85M01H501Q')
        ->and(DB::table('assoc_receipts')->where('id', $full->id)->value('holder'))->not->toContain('RSSMRA');

    $member->update(['tax_code' => 'VRDGPP70A01F205X']);

    expect($full->refresh()->holder['tax_code'])->toBe('RSSMRA85M01H501Q')
        ->and(substr((string) $this->get("/api/v1/associations/receipts/{$full->id}/pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF');
});

test('an online payment is confirmed by a signed callback, once, for the stored amount', function (): void {
    openFiscalPeriod($this->tenant);
    $instalment = assignFee(Member::factory()->create(), feePlan(FeeFrequency::OneOff, '45.50'))->instalments->first();
    $link = $this->postJson("/api/v1/associations/instalments/{$instalment->id}/payment-link")->assertCreated()->json('data');
    $sign = fn (string $amount, string $status): string => LocalFeePaymentGateway::signature($link['reference'], $amount, $status);

    $this->postJson('/api/v1/association-payments/callback', ['reference' => $link['reference'], 'amount' => '45.50', 'status' => 'paid'], [LocalFeePaymentGateway::SignatureHeader => 'forged'])->assertForbidden();
    $this->postJson('/api/v1/association-payments/callback', ['reference' => $link['reference'], 'amount' => '1.00', 'status' => 'paid'], [LocalFeePaymentGateway::SignatureHeader => $sign('1.00', 'paid')])->assertUnprocessable();

    expect(FeePayment::query()->count())->toBe(0);

    foreach ([1, 2] as $delivery) {
        $this->postJson('/api/v1/association-payments/callback', ['reference' => $link['reference'], 'amount' => '45.50', 'status' => 'paid'], [LocalFeePaymentGateway::SignatureHeader => $sign('45.50', 'paid')])
            ->assertOk()->assertJsonPath('data.status', 'paid');
    }

    expect(FeePayment::query()->count())->toBe(1)
        ->and($instalment->refresh()->status)->toBe(InstalmentStatus::Paid)
        ->and(FeePayment::query()->firstOrFail()->invoice_id)->not->toBeNull();
});

test('reminders go out at the configured days, once, to the payer', function (): void {
    AssociationSetting::current()->update(['fee_reminder_days' => [10, 3]]);
    $member = Member::factory()->create();
    $today = CarbonImmutable::today();
    $assignment = assignFee($member, feePlan(FeeFrequency::OneOff, '10.00'), ['starts_on' => $today->addDays(10)->toDateString(), 'ends_on' => $today->addMonth()->toDateString()]);
    assignFee(Member::factory()->create(), feePlan(FeeFrequency::OneOff, '10.00'), ['starts_on' => $today->addDays(11)->toDateString(), 'ends_on' => $today->addMonth()->toDateString()]);

    $reminders = app(ReminderService::class);

    expect($reminders->sendFeeReminders())->toBe(1)
        ->and($reminders->sendFeeReminders())->toBe(0);

    Mail::assertSent(AssociationNoticeMail::class, 1);
    Mail::assertSent(AssociationNoticeMail::class, fn (AssociationNoticeMail $mail): bool => $mail->hasTo($member->contact->email));

    $assignment->instalments->first()->update(['status' => InstalmentStatus::Paid]);
});

test('money is calculated in decimal strings', function (): void {
    expect(Money::add('0.10', '0.20'))->toBe('0.30')
        ->and(Money::percentage('99.99', '10'))->toBe('10.00')
        ->and(Money::split('10.00', 3))->toBe(['3.33', '3.33', '3.34'])
        ->and(Money::netOfVat('122.00', '22'))->toBe('100.00')
        ->and(Money::normalize('12.5'))->toBe('12.50');
});

test('an amount with VAT splits so that the invoice total matches the receipt', function (): void {
    openFiscalPeriod($this->tenant);
    $instalment = assignFee(Member::factory()->create(), feePlan(FeeFrequency::OneOff, '99.99', ['tax_rate' => '22.00']))->instalments->first();

    $id = $this->postJson("/api/v1/associations/instalments/{$instalment->id}/payments", ['amount' => '99.99', 'paid_on' => now()->toDateString(), 'method' => 'card'])->assertCreated()->json('data.id');

    expect((string) Invoice::query()->findOrFail(FeePayment::query()->findOrFail($id)->invoice_id)->total_gross)->toBe('99.99');
});

test('fees of another tenant cannot be touched, and an accountant can record payments but not edit the registry', function (): void {
    AssociationSetting::current()->update(['ledger_enabled' => false]);
    $foreign = inTenant($this->otherTenant, fn () => assignFee(Member::factory()->create(), feePlan(FeeFrequency::OneOff))->instalments->first());

    $this->postJson("/api/v1/associations/instalments/{$foreign->id}/payments", ['amount' => '1.00', 'paid_on' => now()->toDateString(), 'method' => 'cash'])->assertNotFound();

    $instalment = assignFee(Member::factory()->create(), feePlan(FeeFrequency::OneOff))->instalments->first();

    actAs($this, 'accountant');

    $this->postJson("/api/v1/associations/instalments/{$instalment->id}/payments", ['amount' => '1.00', 'paid_on' => now()->toDateString(), 'method' => 'cash'])->assertCreated();
    $this->postJson('/api/v1/associations/members', ['first_name' => 'A', 'last_name' => 'B'])->assertForbidden();
    $this->getJson('/api/v1/associations/certificates/expired')->assertForbidden();
});

test('the PDF generator gets the receipt of the requested tenant only', function (): void {
    AssociationSetting::current()->update(['ledger_enabled' => false]);
    $this->mock(PdfGenerator::class)->shouldNotReceive('render');
    $foreign = inTenant($this->otherTenant, fn () => Receipt::query()->create([
        'number' => 'X-1', 'fiscal_year' => 2026, 'sequential_number' => 1, 'kind' => 'simple', 'member_id' => Member::factory()->create()->id,
        'issued_on' => now(), 'amount' => '1.00', 'description' => 'Fee', 'holder' => ['name' => 'X'],
    ]));

    $this->get("/api/v1/associations/receipts/{$foreign->id}/pdf", ['Accept' => 'application/json'])->assertNotFound();
});
