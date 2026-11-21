<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Hse\Domain\Enums\Jurisdiction;
use Modules\Hse\Infrastructure\Models\PpeDistributionRecord;
use Modules\Hse\Infrastructure\Support\VerbaleLabels;
use Modules\HumanResources\Models\EmployeeDocument;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockQuant;

require_once __DIR__.'/HseTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::statement('PRAGMA defer_foreign_keys = ON');
    Storage::fake((string) config('filesystems.private_disk', config('filesystems.default', 'local')));
    $this->tenant = hseTenant('CH');
    $this->user = hseActAs($this->tenant, 'hse');
    $this->employee = hseEmployee($this->tenant);
    $this->stock = hsePpeStock($this->tenant, 5);
});

function hsePpePayload(object $test, array $overrides = []): array
{
    return [
        'employee_id' => (string) $test->employee->id, 'product_id' => $test->stock['product_id'], 'warehouse_id' => $test->stock['warehouse_id'],
        'lot_or_serial_id' => 'LOT-2027-01', 'quantity' => 2, 'ce_marking_standard' => 'EN ISO 20345', 'handed_over_at' => '2027-06-15T09:30:00+02:00',
        'replacement_due_at' => '2028-06-15', 'signature_payload' => '[[10,20],[30,45],[60,80]]', 'locale' => 'de-CH', ...$overrides,
    ];
}

it('decrements the warehouse stock, hashes the signature and archives the Verbale PDF in the dossier', function (): void {
    $response = $this->postJson('/api/v1/hse/ppe-distributions', hsePpePayload($this))->assertCreated();

    $record = PpeDistributionRecord::query()->findOrFail($response->json('data.id'));
    $expectedHash = hash('sha256', implode('|', [$this->employee->id, $this->stock['product_id'], 'LOT-2027-01', $record->handed_over_at->toIso8601String(), '[[10,20],[30,45],[60,80]]']));

    expect((float) StockQuant::query()->where('stock_item_id', $this->stock['stock_item']->id)->value('quantity_on_hand'))->toBe(3.0)
        ->and(StockMovement::query()->where('origin_document_id', $record->id)->count())->toBe(1)
        ->and($record->employee_signature_hash)->toBe($expectedHash)
        ->and($record->stock_movement_id)->not->toBeNull()
        ->and($record->verbale_consegna_pdf_id)->not->toBeNull();

    $document = EmployeeDocument::query()->findOrFail($record->verbale_consegna_pdf_id);
    $pdf = Storage::disk((string) config('filesystems.private_disk', config('filesystems.default', 'local')))->get($document->file_path);

    expect($document->employee_id)->toBe($this->employee->id)->and($document->document_type)->toBe('ppe_delivery_record')
        ->and(str_starts_with((string) $pdf, '%PDF'))->toBeTrue();
});

it('refuses a hand-over beyond the available stock and leaves stock and records untouched', function (): void {
    $this->postJson('/api/v1/hse/ppe-distributions', hsePpePayload($this, ['quantity' => 9]))
        ->assertUnprocessable()->assertJsonPath('title', 'HSE Rule Violation');

    expect((float) StockQuant::query()->where('stock_item_id', $this->stock['stock_item']->id)->value('quantity_on_hand'))->toBe(5.0)
        ->and(PpeDistributionRecord::query()->count())->toBe(0)
        ->and(EmployeeDocument::query()->count())->toBe(0);
});

it('serves the signed record back as a PDF and hides the signature image from the API', function (): void {
    $id = $this->postJson('/api/v1/hse/ppe-distributions', hsePpePayload($this, ['signature_image' => 'iVBORw0KGgo=']))->assertCreated()->json('data.id');

    $this->getJson('/api/v1/hse/ppe-distributions')->assertOk()->assertJsonMissingPath('data.0.signature_image_base64_or_ref');
    $this->get("/api/v1/hse/ppe-distributions/{$id}/verbale")->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('renders the legal wording of the jurisdiction in the locale of the hand-over', function (): void {
    $record = new PpeDistributionRecord(['locale' => 'it-IT', 'quantity' => 1, 'handed_over_at' => now(), 'employee_signature_hash' => str_repeat('c', 64)]);

    $italian = VerbaleLabels::for('it-IT', Jurisdiction::Italy);
    $swiss = VerbaleLabels::for('de-CH', Jurisdiction::Switzerland);

    expect($italian['legal'])->toContain('75-78')->and($italian['title'])->toBe('Verbale di consegna DPI')
        ->and($swiss['legal'])->toContain('Art. 5 und 90')->and($swiss['title'])->toBe('Übergabeprotokoll PSA')
        ->and($record->locale)->toBe('it-IT');
});

it('forbids a plain member from issuing PPE', function (): void {
    hseActAs($this->tenant, 'member');

    $this->postJson('/api/v1/hse/ppe-distributions', hsePpePayload($this))->assertForbidden();
});
