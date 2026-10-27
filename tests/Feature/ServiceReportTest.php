<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\LookupItem;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\ComprehensiveLookupMasterSeeder;
use Database\Seeders\ServiceReportLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const TEST_PNG_SIG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

beforeEach(function (): void {
    $this->travelTo('2026-04-10 10:00:00');
    Storage::fake('local');

    $this->tenant = Tenant::create([
        'name' => 'FatturaFacile Electricians',
        'legal_name' => 'FatturaFacile Electricians Sagl',
        'vat_number' => 'CHE-123.456.789',
        'country' => 'CH',
        'default_currency' => 'EUR',
    ]);

    $this->user = User::create([
        'name' => 'Mario Rossi',
        'email' => 'mario@fatturafacile.ch',
        'password' => bcrypt('secret123'),
        'locale' => 'en',
    ]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);

    $this->contact = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Hotel Bellavista',
        'country' => 'CH',
        'email' => 'info@bellavista.ch',
    ]);

    // Seed lookups
    $this->seed(ComprehensiveLookupMasterSeeder::class);
    $this->seed(ServiceReportLookupSeeder::class);

    $this->laborType = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'service_report_line_type'))
        ->where('code', 'labor')
        ->firstOrFail();

    $this->materialType = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'service_report_line_type'))
        ->where('code', 'material')
        ->firstOrFail();

    $this->payload = [
        'contact_id' => $this->contact->id,
        'technician_id' => $this->user->id,
        'report_date' => '2026-04-10',
        'time_start' => '08:00',
        'time_end' => '11:30',
        'travel_distance_km' => 15.5,
        'site_address' => 'Via Cantonale 12, Lugano',
        'customer_notes' => 'Replaced electrical switchboard components.',
        'internal_notes' => 'Technician note: main breaker was obsolete.',
        'lines' => [
            [
                'line_type_id' => $this->laborType->id,
                'description' => 'Electrical intervention labor',
                'quantity' => 3.5,
                'unit_of_measure' => 'hours',
                'unit_price' => 90.0,
                'tax_rate' => 8.1,
                'discount_percent' => 0.0,
                'is_billable' => true,
            ],
            [
                'line_type_id' => $this->materialType->id,
                'description' => 'Schneider 40A Circuit Breaker',
                'quantity' => 2,
                'unit_of_measure' => 'pcs',
                'unit_price' => 75.0,
                'tax_rate' => 8.1,
                'discount_percent' => 10.0,
                'is_billable' => true,
            ],
        ],
    ];
});

function srApi()
{
    return test()->withHeader('X-Tenant-ID', test()->tenant->id);
}

test('a service report is created with numbering, computed line totals and status', function (): void {
    $response = srApi()->postJson('/api/v1/service-reports', $this->payload)->assertCreated();

    expect($response->json('data.report_number'))->toBe('SR-2026-0001')
        ->and($response->json('data.technician_id'))->toBe($this->user->id)
        ->and($response->json('data.contact_id'))->toBe($this->contact->id)
        ->and((float) $response->json('data.total_hours'))->toEqual(3.5)
        ->and((float) $response->json('data.total_net'))->toEqual(450.0) // 315 + 135
        ->and((float) $response->json('data.total_tax'))->toEqual(36.45) // 450 * 8.1%
        ->and((float) $response->json('data.total_gross'))->toEqual(486.45)
        ->and($response->json('data.lines'))->toHaveCount(2)
        ->and($response->json('data.is_signed'))->toBeFalse()
        ->and($response->json('data.is_invoiced'))->toBeFalse();

    srApi()->getJson('/api/v1/service-reports/'.$response->json('data.id'))
        ->assertOk()
        ->assertJsonPath('data.contact.name', 'Hotel Bellavista');
});

test('service report can be updated before it is signed', function (): void {
    $createRes = srApi()->postJson('/api/v1/service-reports', $this->payload)->assertCreated();
    $id = $createRes->json('data.id');

    $updatePayload = [
        'customer_notes' => 'Updated: also verified generator.',
        'travel_distance_km' => 20.0,
    ];

    $updateRes = srApi()->putJson("/api/v1/service-reports/{$id}", $updatePayload)->assertOk();
    expect($updateRes->json('data.customer_notes'))->toBe('Updated: also verified generator.')
        ->and((float) $updateRes->json('data.travel_distance_km'))->toEqual(20.0);
});

test('service report can be signed with touchscreen base64 PNG signature', function (): void {
    $createRes = srApi()->postJson('/api/v1/service-reports', $this->payload)->assertCreated();
    $id = $createRes->json('data.id');

    $signPayload = [
        'signature_base64' => TEST_PNG_SIG,
        'signer_name' => 'Franco Rossi',
        'signer_role' => 'Hotel Director',
        'signer_email' => 'director@bellavista.ch',
    ];

    $signRes = srApi()->postJson("/api/v1/service-reports/{$id}/sign", $signPayload)->assertOk();

    expect($signRes->json('data.is_signed'))->toBeTrue()
        ->and($signRes->json('data.status.code'))->toBe('signed')
        ->and($signRes->json('data.signatures'))->toHaveCount(1)
        ->and($signRes->json('data.signatures.0.signer_name'))->toBe('Franco Rossi');

    // Signed reports cannot be modified anymore
    srApi()->putJson("/api/v1/service-reports/{$id}", ['travel_distance_km' => 50])->assertUnprocessable();
});

test('signed service report generates a direct 1-to-1 invoice', function (): void {
    $createRes = srApi()->postJson('/api/v1/service-reports', $this->payload)->assertCreated();
    $id = $createRes->json('data.id');

    // Attempting to invoice before signing fails
    srApi()->postJson("/api/v1/service-reports/{$id}/generate-invoice")->assertUnprocessable();

    // Sign report
    srApi()->postJson("/api/v1/service-reports/{$id}/sign", [
        'signature_base64' => TEST_PNG_SIG,
        'signer_name' => 'Franco Rossi',
    ])->assertOk();

    // Generate Invoice
    $invoiceRes = srApi()->postJson("/api/v1/service-reports/{$id}/generate-invoice")->assertCreated();

    expect($invoiceRes->json('data.invoice_number'))->toStartWith('INV-2026-')
        ->and((float) $invoiceRes->json('data.total_net'))->toEqual(450.0)
        ->and($invoiceRes->json('data.items'))->toHaveCount(2);

    // Verify report is now marked as invoiced
    $reportRes = srApi()->getJson("/api/v1/service-reports/{$id}")->assertOk();
    expect($reportRes->json('data.is_invoiced'))->toBeTrue()
        ->and($reportRes->json('data.status.code'))->toBe('invoiced')
        ->and($reportRes->json('data.invoice_id'))->toBe($invoiceRes->json('data.id'));

    // Attempting to invoice a second time fails
    srApi()->postJson("/api/v1/service-reports/{$id}/generate-invoice")->assertUnprocessable();
});

test('multiple signed service reports generate a consolidated invoice', function (): void {
    // Report 1
    $rep1 = srApi()->postJson('/api/v1/service-reports', $this->payload)->assertCreated()->json('data.id');
    srApi()->postJson("/api/v1/service-reports/{$rep1}/sign", [
        'signature_base64' => TEST_PNG_SIG,
        'signer_name' => 'Franco Rossi',
    ])->assertOk();

    // Report 2 (same customer, different date)
    $payload2 = $this->payload;
    $payload2['report_date'] = '2026-04-12';
    $payload2['lines'] = [
        [
            'line_type_id' => $this->laborType->id,
            'description' => 'Follow-up maintenance',
            'quantity' => 1.0,
            'unit_price' => 100.0,
            'tax_rate' => 8.1,
            'discount_percent' => 0.0,
            'is_billable' => true,
        ],
    ];
    $rep2 = srApi()->postJson('/api/v1/service-reports', $payload2)->assertCreated()->json('data.id');
    srApi()->postJson("/api/v1/service-reports/{$rep2}/sign", [
        'signature_base64' => TEST_PNG_SIG,
        'signer_name' => 'Franco Rossi',
    ])->assertOk();

    // Generate Consolidated Invoice
    $consolidatedRes = srApi()->postJson('/api/v1/service-reports/generate-consolidated-invoice', [
        'report_ids' => [$rep1, $rep2],
    ])->assertCreated();

    expect($consolidatedRes->json('data.invoice_number'))->toStartWith('INV-2026-')
        ->and((float) $consolidatedRes->json('data.total_net'))->toEqual(550.0) // 450 + 100
        ->and($consolidatedRes->json('data.items'))->toHaveCount(3);

    // Both reports are now marked invoiced
    srApi()->getJson("/api/v1/service-reports/{$rep1}")->assertOk()->assertJsonPath('data.is_invoiced', true);
    srApi()->getJson("/api/v1/service-reports/{$rep2}")->assertOk()->assertJsonPath('data.is_invoiced', true);
});

test('cannot generate consolidated invoice if reports belong to different customers', function (): void {
    $otherCustomer = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Different SpA',
        'country' => 'IT',
    ]);

    $rep1 = srApi()->postJson('/api/v1/service-reports', $this->payload)->assertCreated()->json('data.id');
    srApi()->postJson("/api/v1/service-reports/{$rep1}/sign", [
        'signature_base64' => TEST_PNG_SIG,
        'signer_name' => 'Franco Rossi',
    ])->assertOk();

    $payloadDiff = $this->payload;
    $payloadDiff['contact_id'] = $otherCustomer->id;
    $rep2 = srApi()->postJson('/api/v1/service-reports', $payloadDiff)->assertCreated()->json('data.id');
    srApi()->postJson("/api/v1/service-reports/{$rep2}/sign", [
        'signature_base64' => TEST_PNG_SIG,
        'signer_name' => 'Giacomo Verde',
    ])->assertOk();

    srApi()->postJson('/api/v1/service-reports/generate-consolidated-invoice', [
        'report_ids' => [$rep1, $rep2],
    ])->assertUnprocessable();
});

test('photos can be uploaded and attached to service report', function (): void {
    $createRes = srApi()->postJson('/api/v1/service-reports', $this->payload)->assertCreated();
    $id = $createRes->json('data.id');

    $file = UploadedFile::fake()->image('broken_switch.jpg', 600, 400);

    $photoRes = srApi()->postJson("/api/v1/service-reports/{$id}/upload-photo", [
        'photo' => $file,
        'stage' => 'before',
        'caption' => 'Burned terminal on breaker 3',
    ])->assertCreated();

    expect($photoRes->json('data.stage'))->toBe('before')
        ->and($photoRes->json('data.original_name'))->toBe('broken_switch.jpg');

    srApi()->getJson("/api/v1/service-reports/{$id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.attachments');
});

test('service report PDF can be rendered and streamed', function (): void {
    $createRes = srApi()->postJson('/api/v1/service-reports', $this->payload)->assertCreated();
    $id = $createRes->json('data.id');

    $pdfRes = srApi()->get("/api/v1/service-reports/{$id}/pdf");

    $pdfRes->assertOk();
    expect($pdfRes->headers->get('content-type'))->toBe('application/pdf');
});
