<?php

declare(strict_types=1);

use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Workshop\Models\WorkshopFolder;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-09 08:00:00');
    $this->tenant = verticalTenant();
    $this->customer = verticalContact($this->tenant);
    $this->vehicle = Vehicle::factory()->create(['tenant_id' => $this->tenant->id, 'client_id' => $this->customer->id, 'license_plate' => 'TI 123456']);
    $this->user = verticalMember($this->tenant, 'admin');
    verticalApi($this->user, $this->tenant);

    $this->payload = fn (array $extra = []): array => [
        'vehicle_id' => $this->vehicle->id,
        'kind' => 'mechanics',
        'currency' => 'CHF',
        'mileage_in' => 42000,
        'complaint' => 'Rumore freni',
        'lines' => [
            ['line_type' => 'labour', 'description' => 'Sostituzione pastiglie', 'quantity' => '1.5', 'unit_price' => '120', 'tax_rate' => '8.1'],
            ['line_type' => 'part', 'description' => 'Pastiglie', 'quantity' => '1', 'unit_price' => '80.50', 'discount_percentage' => '10', 'tax_rate' => '8.1'],
        ],
        ...$extra,
    ];
});

test('an estimate is created with lines and calculated totals', function (): void {
    $estimate = $this->postJson('/api/v1/workshop/estimates', ($this->payload)())->assertCreated()
        ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.contact_id', $this->customer->id)->assertJsonCount(2, 'data.lines');

    // 1.5 x 120 = 180.00 plus 80.50 less 10% = 72.45 gives 252.45; tax 14.58 + 5.87.
    expect($estimate->json('data'))->toMatchArray(['subtotal' => '252.45', 'tax_total' => '20.45', 'total' => '272.90'])
        ->and($estimate->json('data.estimate_number'))->toStartWith('EST-');

    $id = $estimate->json('data.id');
    $line = $this->postJson("/api/v1/workshop/estimates/{$id}/lines", ['line_type' => 'paint', 'description' => 'Vernice', 'quantity' => '1', 'unit_price' => '50', 'tax_rate' => '8.1'])->assertCreated()->json('data.id');
    $this->getJson("/api/v1/workshop/estimates/{$id}")->assertOk()->assertJsonPath('data.subtotal', '302.45');
    $this->deleteJson("/api/v1/workshop/estimates/{$id}/lines/{$line}")->assertNoContent();
    $this->getJson("/api/v1/workshop/estimates/{$id}")->assertOk()->assertJsonPath('data.subtotal', '252.45');
    $this->putJson("/api/v1/workshop/estimates/{$id}", ['complaint' => 'Rumore freni anteriori'])->assertOk()->assertJsonPath('data.complaint', 'Rumore freni anteriori');
    $this->getJson('/api/v1/workshop/estimates?status=draft')->assertOk()->assertJsonCount(1, 'data');
});

test('the payload is validated', function (): void {
    $this->postJson('/api/v1/workshop/estimates', ['vehicle_id' => $this->vehicle->id])->assertUnprocessable()->assertJsonValidationErrors(['kind', 'currency']);
    $this->postJson('/api/v1/workshop/estimates', ($this->payload)(['lines' => [['line_type' => 'labour', 'description' => 'x', 'quantity' => '0', 'unit_price' => '1', 'tax_rate' => '8.1']]]))->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');
});

test('an estimate is sent, approved and converted into a folder with the same lines, once', function (): void {
    $id = $this->postJson('/api/v1/workshop/estimates', ($this->payload)(['valid_until' => '2026-11-30']))->assertCreated()->json('data.id');

    $this->postJson("/api/v1/workshop/estimates/{$id}/approve")->assertUnprocessable();
    $this->postJson("/api/v1/workshop/estimates/{$id}/convert")->assertUnprocessable();
    $this->postJson("/api/v1/workshop/estimates/{$id}/send")->assertOk()->assertJsonPath('data.status', 'sent');
    $this->postJson("/api/v1/workshop/estimates/{$id}/lines", ['line_type' => 'other', 'description' => 'x', 'quantity' => '1', 'unit_price' => '1', 'tax_rate' => '8.1'])->assertUnprocessable();
    $this->postJson("/api/v1/workshop/estimates/{$id}/approve", ['note' => 'Per telefono'])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decision_note', 'Per telefono');

    $folder = $this->postJson("/api/v1/workshop/estimates/{$id}/convert")->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonCount(2, 'data.lines');
    expect($folder->json('data'))->toMatchArray(['vehicle_id' => $this->vehicle->id, 'mileage_in' => 42000, 'complaint' => 'Rumore freni', 'currency' => 'CHF']);
    expect(collect($folder->json('data.lines'))->pluck('line_total')->all())->toBe(['180.00', '72.45']);

    $this->getJson("/api/v1/workshop/estimates/{$id}")->assertOk()->assertJsonPath('data.status', 'converted')->assertJsonPath('data.folder_id', $folder->json('data.id'));
    $this->postJson("/api/v1/workshop/estimates/{$id}/convert")->assertUnprocessable();
    expect(WorkshopFolder::query()->count())->toBe(1);
});

test('a rejected estimate stays final and cannot be converted', function (): void {
    $id = $this->postJson('/api/v1/workshop/estimates', ($this->payload)())->assertCreated()->json('data.id');
    $this->postJson("/api/v1/workshop/estimates/{$id}/send")->assertOk();
    $this->postJson("/api/v1/workshop/estimates/{$id}/reject", ['note' => 'Troppo caro'])->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_note', 'Troppo caro');
    $this->postJson("/api/v1/workshop/estimates/{$id}/approve")->assertUnprocessable();
    $this->postJson("/api/v1/workshop/estimates/{$id}/convert")->assertUnprocessable();
    $this->deleteJson("/api/v1/workshop/estimates/{$id}")->assertUnprocessable();
});

test('an estimate needs lines to be sent and cannot be approved after its validity date', function (): void {
    $empty = $this->postJson('/api/v1/workshop/estimates', ($this->payload)(['lines' => []]))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/workshop/estimates/{$empty}/send")->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'no lines'));
    $this->deleteJson("/api/v1/workshop/estimates/{$empty}")->assertNoContent();

    $id = $this->postJson('/api/v1/workshop/estimates', ($this->payload)(['valid_until' => '2026-11-10']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/workshop/estimates/{$id}/send")->assertOk();
    $this->travelTo('2026-11-12 08:00:00');
    $this->postJson("/api/v1/workshop/estimates/{$id}/approve")->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'expired'));
});

test('read-only roles and other companies stay out of the estimates', function (): void {
    $id = $this->postJson('/api/v1/workshop/estimates', ($this->payload)())->assertCreated()->json('data.id');

    verticalApi(verticalMember($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->getJson("/api/v1/workshop/estimates/{$id}")->assertOk();
    $this->postJson("/api/v1/workshop/estimates/{$id}/send")->assertForbidden();

    $other = verticalTenant();
    verticalApi(verticalMember($other, 'admin'), $other);
    $this->getJson("/api/v1/workshop/estimates/{$id}")->assertNotFound();
});
