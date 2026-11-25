<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\DebtCollection\Models\DunningCase;
use Modules\DebtCollection\Models\EnforcementOffice;

require_once __DIR__.'/DebtCollectionSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    dcSetUp($this);
    Storage::fake('local');
    treasuryInTenant($this->tenant, fn () => EnforcementOffice::create(['name' => 'Betreibungsamt Zürich 1', 'postal_code_from' => '8000', 'postal_code_to' => '8099', 'address' => 'Amtshaus', 'postal_code' => '8001', 'city' => 'Zürich', 'canton' => 'ZH']));
    treasuryInTenant($this->tenant, fn () => EnforcementOffice::create(['name' => 'Betreibungsamt Bern', 'postal_code_from' => '3000', 'postal_code_to' => '3099', 'city' => 'Bern', 'canton' => 'BE']));
    $this->customer = dcCustomer($this, 'Muster AG');
    $this->itemA = dcItem($this, $this->customer, 'INV-A', '1000.00', '2025-01-01');
    $this->itemB = dcItem($this, $this->customer, 'INV-B', '500.00', '2025-02-01');
});

/**
 * Generates the dossier of both items on 15 March 2025.
 */
function dcDossier(object $test, array $overrides = []): TestResponse
{
    return $test->postJson('/api/v1/debt-collection/dossiers', array_merge([
        'customer_id' => $test->customer->id,
        'receivable_item_ids' => [$test->itemA->id, $test->itemB->id],
        'locale' => 'de',
        'as_of' => '2025-03-15',
    ], $overrides));
}

it('assembles the dossier with the claim at 5% interest and the office found from the postal code', function (): void {
    // INV-A: 73 days, 1000 x 5% x 73 / 365 = 10.00. INV-B: 42 days, 500 x 5% x 42 / 365 = 2.88.
    $response = dcDossier($this)->assertCreated();

    $response->assertJsonPath('data.number', 'BB-2025-00001')
        ->assertJsonPath('data.status', 'generated')
        ->assertJsonPath('data.principal', '1500.00')
        ->assertJsonPath('data.interest', '12.88')
        ->assertJsonPath('data.fee', '0.00')
        ->assertJsonPath('data.total', '1512.88')
        ->assertJsonPath('data.office.name', 'Betreibungsamt Zürich 1')
        ->assertJsonPath('data.has_bundle', true);
});

it('keeps the claim of the notices already sent as dunning costs', function (): void {
    dcCase($this, $this->itemA, 1, '2025-02-01', '2025-02-10');
    $notice = $this->postJson('/api/v1/debt-collection/proposals/generate', [
        'groups' => [['customer_id' => $this->customer->id, 'currency' => 'CHF', 'level' => 2, 'receivable_item_ids' => [$this->itemA->id]]],
        'as_of' => '2025-02-15', 'channel' => 'registered_mail', 'dispatch' => true,
    ])->assertCreated()->json('data.notices.0');

    expect($notice['fee'])->toBe('20.00');

    dcDossier($this, ['receivable_item_ids' => [$this->itemA->id]])->assertCreated()->assertJsonPath('data.fee', '20.00');
});

it('downloads the request form as a PDF and the bundle as a ZIP with the manifest', function (): void {
    $id = dcDossier($this)->assertCreated()->json('data.id');

    $pdf = $this->get("/api/v1/debt-collection/dossiers/{$id}/pdf")->assertOk();
    $bundle = $this->get("/api/v1/debt-collection/dossiers/{$id}/bundle")->assertOk();

    expect(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF')
        ->and($bundle->headers->get('Content-Type'))->toBe('application/zip');

    $path = tempnam(sys_get_temp_dir(), 'dct');
    file_put_contents($path, $bundle->getContent());
    $zip = new ZipArchive;
    $zip->open($path);

    expect($zip->locateName('manifest.json'))->not->toBeFalse()
        ->and($zip->locateName('01-betreibungsbegehren-BB-2025-00001.pdf'))->not->toBeFalse();

    $zip->close();
    @unlink($path);
});

it('puts the cases of the dossier in enforcement so they are no longer dunned', function (): void {
    dcDossier($this)->assertCreated();

    expect(DunningCase::query()->where('receivable_item_id', $this->itemA->id)->value('status'))->toBe('enforcement');
});

it('marks the dossier as submitted once and not again', function (): void {
    $id = dcDossier($this)->assertCreated()->json('data.id');

    $this->postJson("/api/v1/debt-collection/dossiers/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
    $this->postJson("/api/v1/debt-collection/dossiers/{$id}/submit")->assertUnprocessable()->assertJsonPath('code', 'dossier_not_submittable');
});

it('refuses the dossier for a debtor outside Switzerland, an item that is not overdue or a mix of currencies', function (): void {
    $italian = dcCustomer($this, 'Rossi Srl', 'IT');
    $itemIt = dcItem($this, $italian, 'FT-1', '100.00', '2025-01-01', 'EUR');
    $future = dcItem($this, $this->customer, 'INV-F', '100.00', '2025-06-01');
    $euro = dcItem($this, $this->customer, 'INV-E', '100.00', '2025-01-01', 'EUR');

    dcDossier($this, ['customer_id' => $italian->id, 'receivable_item_ids' => [$itemIt->id]])->assertUnprocessable()->assertJsonPath('code', 'wrong_jurisdiction');
    dcDossier($this, ['receivable_item_ids' => [$future->id]])->assertUnprocessable()->assertJsonPath('code', 'item_not_overdue');
    dcDossier($this, ['receivable_item_ids' => [$this->itemA->id, $euro->id]])->assertUnprocessable()->assertJsonPath('code', 'mixed_currency');
});

it('uses the office the user picks over the one found from the postal code', function (): void {
    $bern = EnforcementOffice::query()->where('name', 'Betreibungsamt Bern')->firstOrFail();

    dcDossier($this, ['office_id' => $bern->id])->assertCreated()->assertJsonPath('data.office.name', 'Betreibungsamt Bern');
});

it('does not show the dossiers of another company', function (): void {
    $id = dcDossier($this)->assertCreated()->json('data.id');

    treasuryActor($this, 'owner', Tenant::create(['name' => 'Altra SA', 'legal_name' => 'Altra SA', 'country' => 'CH', 'default_currency' => 'CHF']));

    $this->getJson("/api/v1/debt-collection/dossiers/{$id}")->assertNotFound();
});
