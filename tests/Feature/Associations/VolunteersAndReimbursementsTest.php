<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\Associations\Mail\AssociationNoticeMail;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\Campaign;
use Modules\Associations\Models\Reimbursement;
use Modules\Associations\Models\Shift;
use Modules\Associations\Models\Volunteer;
use Modules\Associations\Models\VolunteerTraining;
use Modules\Associations\Services\VolunteerAlertService;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\CostCenter;
use Modules\GeneralLedger\Models\JournalEntry;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    Storage::fake('private');
    Mail::fake();
    config(['filesystems.private_disk' => 'private']);
});

afterEach(fn () => associationsTearDown());

function volunteerShift(object $test, int $places = 2): string
{
    return $test->postJson('/api/v1/associations/catalogs/shifts', [
        'title' => 'Ambulance night', 'starts_at' => now()->addDay()->setTime(20, 0)->toIso8601String(),
        'ends_at' => now()->addDays(2)->setTime(6, 0)->toIso8601String(), 'required_volunteers' => $places,
    ])->assertCreated()->json('data.id');
}

function volunteerExpenseAccount(): ChartOfAccount
{
    return ChartOfAccount::query()->create(['account_code' => '6150', 'name' => 'Volunteer expenses', 'account_class' => 'expense', 'account_type' => 'expense', 'normal_balance' => 'debit']);
}

test('volunteers are registered through the catalog with activity, dates and insurance', function (): void {
    actAs($this, 'secretary');
    $member = Volunteer::factory()->make()->member_id;

    $this->postJson('/api/v1/associations/catalogs/volunteers', [
        'member_id' => $member, 'activity' => 'Cook', 'started_on' => now()->toDateString(), 'insurer' => 'Coop', 'insurance_policy' => 'P-1', 'insurance_expires_on' => now()->addMonth()->toDateString(),
    ])->assertCreated();
    $this->postJson('/api/v1/associations/catalogs/volunteers', ['member_id' => $member, 'activity' => 'Cook', 'started_on' => now()->toDateString(), 'ended_on' => now()->subDay()->toDateString()])->assertUnprocessable();

    $this->getJson('/api/v1/associations/volunteers/expiries?days=60')->assertOk()->assertJsonCount(1, 'data.insurance');
});

test('shifts track availability, fill up to their places, and a substitute keeps the coverage', function (): void {
    actAs($this, 'secretary');
    $shift = volunteerShift($this);
    [$anna, $bruno, $carla] = Volunteer::factory()->count(3)->create()->all();

    $this->postJson("/api/v1/associations/shifts/{$shift}/availability", ['volunteer_id' => $carla->id, 'is_available' => true])->assertSuccessful();
    $first = $this->postJson("/api/v1/associations/shifts/{$shift}/assignments", ['volunteer_id' => $anna->id])->assertCreated()->json('data.id');
    $this->getJson("/api/v1/associations/shifts/{$shift}/coverage")->assertJsonPath('data.missing', 1)->assertJsonPath('data.available_volunteer_ids', [$carla->id]);
    $this->postJson("/api/v1/associations/shifts/{$shift}/assignments", ['volunteer_id' => $anna->id])->assertConflict();
    $this->postJson("/api/v1/associations/shifts/{$shift}/assignments", ['volunteer_id' => $bruno->id])->assertCreated();
    $this->postJson("/api/v1/associations/shifts/{$shift}/assignments", ['volunteer_id' => $carla->id])->assertConflict();

    $this->postJson("/api/v1/associations/shift-assignments/{$first}/substitution-request")->assertOk();
    $this->getJson("/api/v1/associations/shifts/{$shift}/coverage")->assertJsonPath('data.missing', 1)->assertJsonPath('data.substitutions_requested', 1);
    $this->getJson('/api/v1/associations/shifts/attention')->assertJsonCount(1, 'data');

    $this->postJson("/api/v1/associations/shift-assignments/{$first}/substitute", ['volunteer_id' => $carla->id])->assertSuccessful()->assertJsonPath('data.volunteer_id', $carla->id);
    $this->getJson("/api/v1/associations/shifts/{$shift}/coverage")->assertJsonPath('data.missing', 0)->assertJsonPath('data.substitutions_requested', 0);
});

test('alerts go out once for uncovered shifts and expiring trainings', function (): void {
    actAs($this, 'secretary');
    $shift = volunteerShift($this, 1);
    $volunteer = Volunteer::factory()->create();
    $this->postJson("/api/v1/associations/shifts/{$shift}/availability", ['volunteer_id' => $volunteer->id])->assertSuccessful();
    VolunteerTraining::query()->create(['volunteer_id' => $volunteer->id, 'title' => 'BLS-D', 'completed_on' => now()->subYears(2)->toDateString(), 'expires_on' => now()->addDays(10)->toDateString()]);

    $first = app(VolunteerAlertService::class)->send();
    $second = app(VolunteerAlertService::class)->send();

    expect($first)->toBe(['shifts' => 1, 'trainings' => 1, 'insurance' => 0])
        ->and($second)->toBe(['shifts' => 0, 'trainings' => 0, 'insurance' => 0]);
    Mail::assertSent(AssociationNoticeMail::class, 2);
});

test('a journey is distance times the unit price, approved and posted to the ledger with the campaign cost centre', function (): void {
    openFiscalPeriod($this->tenant);
    $account = volunteerExpenseAccount();
    $centre = CostCenter::query()->create(['code' => 'CAMP', 'name' => 'Christmas campaign']);
    $campaign = Campaign::query()->create(['code' => 'XMAS', 'name' => 'Christmas', 'cost_center_id' => $centre->id, 'expense_account_id' => $account->id]);
    $volunteer = Volunteer::factory()->create();

    actAs($this, 'secretary');
    $id = $this->postJson('/api/v1/associations/reimbursements', [
        'member_id' => $volunteer->member_id, 'volunteer_id' => $volunteer->id, 'kind' => 'mileage', 'incurred_on' => now()->toDateString(),
        'description' => 'Delivery of parcels', 'vehicle' => 'Fiat Panda', 'plate' => 'AB123CD', 'distance_km' => '42.5', 'unit_price' => '0.2500',
        'campaign_id' => $campaign->id,
    ])->assertCreated()->assertJsonPath('data.amount', '10.63')->json('data.id');

    expect(Reimbursement::query()->findOrFail($id)->getRawOriginal('plate'))->not->toContain('AB123CD');

    $this->post("/api/v1/associations/reimbursements/{$id}/attachments", ['file' => UploadedFile::fake()->create('fuel.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
    $this->postJson("/api/v1/associations/reimbursements/{$id}/pay", ['paid_on' => now()->toDateString(), 'payment_method' => 'cash'])->assertConflict();
    $this->postJson("/api/v1/associations/reimbursements/{$id}/decision", ['approved' => true])->assertOk();
    $paid = $this->postJson("/api/v1/associations/reimbursements/{$id}/pay", ['paid_on' => now()->toDateString(), 'payment_method' => 'cash'])->assertOk()->json('data');

    $entry = JournalEntry::query()->with('lines')->findOrFail($paid['journal_entry_id']);
    $debit = $entry->lines->firstWhere('account_id', $account->id);

    expect($paid['status'])->toBe('paid')
        ->and((string) $debit->debit_amount)->toBe('10.6300')
        ->and($debit->cost_center_id)->toBe($centre->id)
        ->and($entry->source_document_type)->toBe('association_reimbursement');

    $this->postJson("/api/v1/associations/reimbursements/{$id}/pay", ['paid_on' => now()->toDateString(), 'payment_method' => 'cash'])->assertConflict();
    $this->getJson('/api/v1/associations/books/volunteers?year='.now()->year)->assertJsonPath('data.rows.0.10', '10.63');
});

test('an expense without an expense account configured is refused at payment', function (): void {
    openFiscalPeriod($this->tenant);
    $volunteer = Volunteer::factory()->create();
    actAs($this, 'admin');
    $id = $this->postJson('/api/v1/associations/reimbursements', ['member_id' => $volunteer->member_id, 'kind' => 'expense', 'incurred_on' => now()->toDateString(), 'description' => 'Gloves', 'amount' => '12.00'])->json('data.id');
    $this->postJson("/api/v1/associations/reimbursements/{$id}/decision", ['approved' => true])->assertOk();

    $this->postJson("/api/v1/associations/reimbursements/{$id}/pay", ['paid_on' => now()->toDateString(), 'payment_method' => 'bank'])->assertUnprocessable();

    AssociationSetting::current()->update(['reimbursement_account_id' => volunteerExpenseAccount()->id]);
    $this->postJson("/api/v1/associations/reimbursements/{$id}/pay", ['paid_on' => now()->toDateString(), 'payment_method' => 'bank'])->assertOk();
});

test('volunteers and shifts of another company stay hidden', function (): void {
    $foreign = inTenant($this->otherTenant, fn (): Volunteer => Volunteer::factory()->create());
    actAs($this, 'admin');
    $shift = volunteerShift($this);

    $this->postJson("/api/v1/associations/shifts/{$shift}/assignments", ['volunteer_id' => $foreign->id])->assertUnprocessable();
    $this->getJson('/api/v1/associations/catalogs/volunteers')->assertJsonCount(0, 'data');
    expect(Shift::query()->count())->toBe(1);
});
