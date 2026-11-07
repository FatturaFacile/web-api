<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\Campaign;
use Modules\Associations\Models\Donation;
use Modules\Associations\Models\ExpenseCategory;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\CostCenter;
use Modules\GeneralLedger\Models\JournalEntry;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    Storage::fake('private');
    config(['filesystems.private_disk' => 'private']);
    openFiscalPeriod($this->tenant);
    $this->revenue = ChartOfAccount::query()->create(['account_code' => '7200', 'name' => 'Donations', 'account_class' => 'revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit']);
    $this->expense = ChartOfAccount::query()->create(['account_code' => '6400', 'name' => 'Fundraising costs', 'account_class' => 'expense', 'account_type' => 'expense', 'normal_balance' => 'debit']);
    $this->centre = CostCenter::query()->create(['code' => 'XMAS', 'name' => 'Christmas fundraising']);
    $this->campaign = Campaign::query()->create(['code' => 'XMAS26', 'name' => 'Christmas 2026', 'kind' => 'occasional', 'cost_center_id' => $this->centre->id, 'revenue_account_id' => $this->revenue->id, 'expense_account_id' => $this->expense->id]);
});

afterEach(fn () => associationsTearDown());

function fundraisingDonor(object $test): string
{
    return $test->postJson('/api/v1/associations/catalogs/donors', ['name' => 'Mario Bianchi', 'tax_code' => 'BNCMRA70A01H501Z', 'address' => 'Via Roma 1, Milano'])->assertCreated()->json('data.id');
}

test('a donation of money is posted to the ledger on the cost centre of its campaign and gets a numbered receipt', function (): void {
    actAs($this, 'secretary');
    $donor = fundraisingDonor($this);

    $donation = $this->postJson('/api/v1/associations/donations', ['donor_id' => $donor, 'campaign_id' => $this->campaign->id, 'kind' => 'money', 'amount' => '150.00', 'received_on' => now()->toDateString(), 'payment_method' => 'bank'])
        ->assertCreated()->json('data');
    $entry = JournalEntry::query()->with('lines')->findOrFail($donation['journal_entry_id']);
    $credit = $entry->lines->firstWhere('account_id', $this->revenue->id);

    expect((string) $credit->credit_amount)->toBe('150.0000')->and($credit->cost_center_id)->toBe($this->centre->id);

    $receipt = $this->postJson("/api/v1/associations/donations/{$donation['id']}/receipt")->assertCreated()->assertJsonPath('meta.tax_effects_not_stated', true)->json('data');
    $this->postJson("/api/v1/associations/donations/{$donation['id']}/receipt")->assertConflict();

    expect($receipt['number'])->toBe(sprintf('DON-%d-0001', now()->year))
        ->and(DB::table('assoc_donation_receipts')->where('id', $receipt['id'])->value('holder'))->not->toContain('Mario');
    expect($this->get("/api/v1/associations/donation-receipts/{$receipt['id']}/pdf")->assertOk()->headers->get('Content-Type'))->toBe('application/pdf');

    $this->postJson("/api/v1/associations/donation-receipts/{$receipt['id']}/cancel", ['reason' => 'Wrong donor'])->assertOk();
    $this->postJson("/api/v1/associations/donations/{$donation['id']}/receipt")->assertCreated()->assertJsonPath('data.number', sprintf('DON-%d-0002', now()->year));
});

test('a receipt cannot be rewritten or deleted in the database', function (): void {
    actAs($this, 'admin');
    $donation = $this->postJson('/api/v1/associations/donations', ['donor_id' => fundraisingDonor($this), 'kind' => 'money', 'amount' => '10.00', 'received_on' => now()->toDateString(), 'payment_method' => 'cash', 'revenue_account_id' => $this->revenue->id])->json('data.id');
    $receipt = $this->postJson("/api/v1/associations/donations/{$donation}/receipt")->json('data.id');

    expect(fn () => DB::table('assoc_donation_receipts')->where('id', $receipt)->update(['amount' => '1000.00']))->toThrow(Exception::class);
});

test('a donation without a revenue account anywhere is refused and leaves nothing behind', function (): void {
    actAs($this, 'admin');
    $this->postJson('/api/v1/associations/donations', ['kind' => 'money', 'amount' => '10.00', 'received_on' => now()->toDateString(), 'payment_method' => 'cash'])->assertUnprocessable();

    expect(Donation::query()->count())->toBe(0);

    AssociationSetting::current()->update(['donation_revenue_account_id' => $this->revenue->id]);
    $this->postJson('/api/v1/associations/donations', ['kind' => 'money', 'amount' => '10.00', 'received_on' => now()->toDateString(), 'payment_method' => 'cash'])->assertCreated();
    $this->postJson('/api/v1/associations/donations', ['kind' => 'in_kind', 'amount' => '80.00', 'in_kind_description' => 'Ten blankets', 'received_on' => now()->toDateString()])->assertCreated()->assertJsonPath('data.journal_entry_id', null);
});

test('every campaign has its own account: donations, costs and reimbursements charged to it', function (): void {
    $other = Campaign::query()->create(['code' => 'RUN', 'name' => 'Charity run', 'revenue_account_id' => $this->revenue->id]);
    actAs($this, 'secretary');

    $this->postJson('/api/v1/associations/donations', ['campaign_id' => $this->campaign->id, 'kind' => 'money', 'amount' => '300.00', 'received_on' => now()->toDateString(), 'payment_method' => 'cash'])->assertCreated();
    $this->postJson('/api/v1/associations/donations', ['campaign_id' => $other->id, 'kind' => 'money', 'amount' => '999.00', 'received_on' => now()->toDateString(), 'payment_method' => 'cash'])->assertCreated();
    $this->postJson("/api/v1/associations/campaigns/{$this->campaign->id}/expenses", ['incurred_on' => now()->toDateString(), 'description' => 'Flyers', 'amount' => '45.50'])->assertCreated();
    $this->postJson("/api/v1/associations/campaigns/{$other->id}/expenses", ['incurred_on' => now()->toDateString(), 'description' => 'Bibs', 'amount' => '10.00'])->assertUnprocessable();

    $report = $this->getJson("/api/v1/associations/campaigns/{$this->campaign->id}/report")->assertOk()->json('data');

    expect($report['donations']['money'])->toBe('300.00')
        ->and($report['expenses'])->toBe('45.50')
        ->and($report['result'])->toBe('254.50')
        ->and($report['lines'])->toHaveCount(2);
});

test('the 5x1000 is tracked with expenses classified by the categories of the company, never beyond the amount received', function (): void {
    actAs($this, 'admin');
    $staff = $this->postJson('/api/v1/associations/catalogs/expense-categories', ['scope' => 'five_per_mille', 'code' => 'STAFF', 'name' => 'Staff costs'])->assertCreated()->json('data.id');
    $goods = $this->postJson('/api/v1/associations/catalogs/expense-categories', ['scope' => 'five_per_mille', 'code' => 'GOODS', 'name' => 'Goods and services'])->assertCreated()->json('data.id');
    $campaignCategory = ExpenseCategory::query()->create(['scope' => 'campaign', 'code' => 'X', 'name' => 'Not for 5x1000']);
    $allocation = $this->postJson('/api/v1/associations/five-per-mille', ['reference_year' => '2024', 'received_on' => now()->toDateString(), 'amount' => '1000.00'])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/associations/five-per-mille/{$allocation}/expenses", ['category_id' => $staff, 'incurred_on' => now()->toDateString(), 'description' => 'Coordinator', 'amount' => '600.00'])->assertCreated();
    $this->postJson("/api/v1/associations/five-per-mille/{$allocation}/expenses", ['category_id' => $goods, 'incurred_on' => now()->toDateString(), 'description' => 'Food parcels', 'amount' => '250.00'])->assertCreated();
    $this->postJson("/api/v1/associations/five-per-mille/{$allocation}/expenses", ['category_id' => $goods, 'incurred_on' => now()->toDateString(), 'description' => 'Too much', 'amount' => '150.01'])->assertUnprocessable();
    $this->postJson("/api/v1/associations/five-per-mille/{$allocation}/expenses", ['category_id' => $campaignCategory->id, 'incurred_on' => now()->toDateString(), 'description' => 'Wrong', 'amount' => '1.00'])->assertUnprocessable();

    $report = $this->getJson("/api/v1/associations/five-per-mille/{$allocation}/report")->assertOk()->assertJsonPath('meta.official_form_not_reproduced', true)->json('data');

    expect($report['spent'])->toBe('850.00')->and($report['remaining'])->toBe('150.00')->and(array_column($report['categories'], 'code'))->toBe(['GOODS', 'STAFF']);
    expect((string) $this->get("/api/v1/associations/five-per-mille/{$allocation}/csv")->getContent())->toContain('Food parcels');
});

test('sponsors have contracts with the signed document on the private disk', function (): void {
    actAs($this, 'secretary');
    $sponsor = $this->postJson('/api/v1/associations/catalogs/sponsors', ['name' => 'Bakery Rossi', 'vat_number' => 'IT01234567890'])->assertCreated()->json('data.id');
    $contract = $this->postJson('/api/v1/associations/catalogs/sponsor-contracts', ['sponsor_id' => $sponsor, 'title' => 'Shirt sponsorship', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addYear()->toDateString(), 'amount' => '2500.00', 'consideration' => 'Logo on shirts'])->assertCreated()->json('data.id');

    $this->post("/api/v1/associations/sponsor-contracts/{$contract}/document", ['file' => UploadedFile::fake()->create('contract.pdf', 30, 'application/pdf')], ['Accept' => 'application/json'])->assertOk()->assertJsonMissingPath('data.path');
    $this->get("/api/v1/associations/sponsor-contracts/{$contract}/document")->assertOk();
});

test('an accountant reads donations and reports but a member of another company sees none', function (): void {
    actAs($this, 'admin');
    $this->postJson('/api/v1/associations/donations', ['campaign_id' => $this->campaign->id, 'kind' => 'money', 'amount' => '5.00', 'received_on' => now()->toDateString(), 'payment_method' => 'cash'])->assertCreated();

    actAs($this, 'accountant');
    $this->getJson('/api/v1/associations/donations')->assertOk()->assertJsonCount(1, 'data');

    actAs($this, 'admin', tenant: $this->otherTenant);
    $this->getJson('/api/v1/associations/donations')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/associations/campaigns/{$this->campaign->id}/report")->assertNotFound();
});
