<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Inventory\Mail\SafetyDataSheetsMail;
use Modules\Inventory\Models\SafetyDataSheetDelivery;
use Modules\Inventory\Services\SafetyDataSheetService;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesDeliveryNoteItem;

require_once __DIR__.'/fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake((string) config('filesystems.private_disk', config('filesystems.default', 'local')));
    Mail::fake();
    $this->tenant = logisticsTenant();
    logisticsUser($this->tenant);
    $this->product = Product::create(['sku' => 'P-'.Str::random(6), 'name' => 'Solvente']);
    $this->contact = Contact::create(['company_name' => 'Cliente', 'email' => 'cliente@example.com']);
});

function sheetWithVersion(string $language = 'it'): array
{
    $sheet = test()->postJson('/api/v1/inventory/safety-sheets', ['product_id' => test()->product->id, 'language' => $language, 'title' => 'SDS Solvente'])->assertCreated();
    $version = addSheetVersion($sheet->json('id'));

    return [$sheet->json('id'), $version];
}

function addSheetVersion(string $sheetId): string
{
    return test()->post("/api/v1/inventory/safety-sheets/{$sheetId}/versions", [
        'file' => UploadedFile::fake()->create('sds.pdf', 10, 'application/pdf'),
        'revision_date' => '2026-05-01',
        'hazard_classes' => ['Flam. Liq. 2'],
        'signal_word' => 'Pericolo',
    ], ['Accept' => 'application/json'])->assertCreated()->json('id');
}

function deliveryNoteWithProduct(): SalesDeliveryNote
{
    $note = SalesDeliveryNote::create(['tenant_id' => test()->tenant->id, 'contact_id' => test()->contact->id, 'ddt_number' => 'DDT-'.Str::random(6), 'date' => '2026-11-01', 'status' => 'draft']);
    SalesDeliveryNoteItem::create(['tenant_id' => test()->tenant->id, 'delivery_note_id' => $note->id, 'product_id' => test()->product->id, 'description' => 'Solvente', 'quantity' => 1]);

    return $note;
}

test('revisions pile up and the last one is current', function (): void {
    [$sheetId, $first] = sheetWithVersion();
    $second = addSheetVersion($sheetId);

    $this->getJson("/api/v1/inventory/safety-sheets/{$sheetId}/versions")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $second)
        ->assertJsonPath('data.0.version_number', 2)
        ->assertJsonPath('data.1.id', $first)
        ->assertJsonPath('data.0.hazard_classes.0', 'Flam. Liq. 2')
        ->assertJsonMissingPath('data.0.path');

    $this->getJson('/api/v1/inventory/safety-sheets?product_id='.$this->product->id)->assertOk()->assertJsonPath('data.0.current_version_id', $second);
    $this->get("/api/v1/inventory/safety-sheets/versions/{$first}/file")->assertOk();
});

test('a sheet takes pdf files only and one language per product', function (): void {
    [$sheetId] = sheetWithVersion();

    $this->post("/api/v1/inventory/safety-sheets/{$sheetId}/versions", ['file' => UploadedFile::fake()->create('x.exe', 1), 'revision_date' => '2026-05-01', 'hazard_classes' => []], ['Accept' => 'application/json'])->assertStatus(422);
    $this->postJson('/api/v1/inventory/safety-sheets', ['product_id' => $this->product->id, 'language' => 'it', 'title' => 'Bis'])->assertStatus(422);
});

test('dispatching a delivery note sends the current sheet once', function (): void {
    [, $version] = sheetWithVersion();
    $note = deliveryNoteWithProduct();

    $this->postJson("/api/v1/invoicing/delivery-notes/{$note->id}/dispatch")->assertSuccessful();

    Mail::assertSent(SafetyDataSheetsMail::class, fn (SafetyDataSheetsMail $mail): bool => $mail->hasTo('cliente@example.com') && $mail->versions[0]->id === $version);
    Mail::assertSentCount(1);

    $this->postJson("/api/v1/inventory/safety-sheets/deliveries/{$note->id}/send")->assertOk()->assertJsonPath('status', 'nothing_to_send');
    $this->getJson("/api/v1/inventory/safety-sheets/deliveries/{$note->id}")->assertOk()->assertJsonPath('data.0.status', 'sent');
});

test('a new revision goes out with a later send, and products without a sheet send nothing', function (): void {
    [$sheetId] = sheetWithVersion();
    $note = deliveryNoteWithProduct();
    $service = app(SafetyDataSheetService::class);

    expect($service->sendForDelivery($note->id)['status'])->toBe('sent');

    addSheetVersion($sheetId);

    expect($service->sendForDelivery($note->id)['sent'])->toBe(1);

    $note->items()->delete();

    expect($service->sendForDelivery($note->id)['status'])->toBe('nothing_to_send');
});

test('a customer without email is logged and sent later', function (): void {
    sheetWithVersion();
    $this->contact->update(['email' => null]);
    $note = deliveryNoteWithProduct();
    $service = app(SafetyDataSheetService::class);

    expect($service->sendForDelivery($note->id)['status'])->toBe('skipped');
    Mail::assertNothingSent();

    $this->contact->update(['email' => 'nuovo@example.com']);

    expect($service->sendForDelivery($note->id)['status'])->toBe('sent')
        ->and(SafetyDataSheetDelivery::query()->sole()->email)->toBe('nuovo@example.com');
});

test('a mail failure does not stop the delivery', function (): void {
    sheetWithVersion();
    $note = deliveryNoteWithProduct();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    expect(app(SafetyDataSheetService::class)->sendForDelivery($note->id)['status'])->toBe('failed')
        ->and(SafetyDataSheetDelivery::query()->sole()->error)->toBe('smtp down');
});

test('sheets stay within the tenant, and a read-only role cannot upload', function (): void {
    [$sheetId, $version] = sheetWithVersion();

    logisticsUser($this->tenant, 'sales');
    $this->postJson('/api/v1/inventory/safety-sheets', ['product_id' => $this->product->id, 'language' => 'fr', 'title' => 'X'])->assertForbidden();

    logisticsUser(logisticsTenant('Beta'));
    $this->getJson("/api/v1/inventory/safety-sheets/{$sheetId}/versions")->assertNotFound();
    $this->get("/api/v1/inventory/safety-sheets/versions/{$version}/file", ['Accept' => 'application/json'])->assertNotFound();
    $this->postJson('/api/v1/inventory/safety-sheets', ['product_id' => $this->product->id, 'language' => 'de', 'title' => 'X'])->assertStatus(422);
});
