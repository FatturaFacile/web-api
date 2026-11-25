<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\VanSalesAndFieldForce\Domain\Enums\ThermalPrinterProfile;
use Modules\VanSalesAndFieldForce\Domain\Enums\VanFiscalDocumentType;
use Modules\VanSalesAndFieldForce\Models\VanVisitOutcome;
use Modules\VanSalesAndFieldForce\Models\VanWarehouse;
use Modules\VanSalesAndFieldForce\Services\Gs1VariableWeightBarcodeService;
use Modules\VanSalesAndFieldForce\Services\ThermalPrintPayloadBuilderService;

uses(RefreshDatabase::class);

it('parses GS1-128 variable-weight barcodes (AI 01, 3103, 10, 17) and EAN-13 scale barcodes', function (): void {
    $tenant = Tenant::create([
        'name' => 'Dairy Co '.Str::random(4),
        'legal_name' => 'Dairy Co Srl',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    $product = Product::create([
        'tenant_id' => $tenant->id,
        'sku' => 'FORM-001',
        'barcode' => '08001234567893',
        'name' => 'Formaggio Alpino DOP',
        'unit_measure' => 'kg',
        'sale_price' => 20.00,
        'tax_rate' => 4.00,
        'track_inventory' => true,
    ]);

    $parser = app(Gs1VariableWeightBarcodeService::class);

    $gs1 = $parser->parse((string) $tenant->id, '(01)08001234567893(3103)002450(10)LOT-2611(17)261231');
    expect($gs1->format)->toBe('GS1-128')
        ->and($gs1->gtin)->toBe('08001234567893')
        ->and($gs1->netWeightKg)->toBe(2.45)
        ->and($gs1->lotNumber)->toBe('LOT-2611')
        ->and($gs1->expiryDate)->toBe('2026-12-31')
        ->and($gs1->matchedProductId)->toBe((string) $product->id)
        ->and($gs1->computedLineTotal)->toBe(49.0);

    $ean = $parser->parse((string) $tenant->id, '2100450012508');
    expect($ean->format)->toBe('EAN13-VAR-WEIGHT')
        ->and($ean->netWeightKg)->toBe(1.25);
});

it('builds both ESC/POS 80mm and ZPL 104mm thermal printer payloads', function (): void {
    $van = new VanWarehouse([
        'code' => 'VAN-01',
        'license_plate' => 'TI-233480',
        'printer_profile' => ThermalPrinterProfile::Zpl104mm,
        'printer_brand' => 'Zebra ZQ521',
    ]);
    $visit = new VanVisitOutcome([
        'definitive_document_number' => 'FV-TI01/2026/00001',
        'document_type' => VanFiscalDocumentType::ImmediateInvoice,
        'currency' => 'CHF',
        'total_net' => '100.00',
        'total_tax' => '8.10',
        'total_gross' => '108.10',
        'empties_net_deposit_amount' => '12.00',
        'total_collected_amount' => '108.10',
        'qr_bill_generated' => true,
        'qr_bill_reference' => 'CH-QR-TEST123456',
        'sdi_transmission_status' => 'not_applicable',
        'printer_format' => ThermalPrinterProfile::Zpl104mm,
        'visited_at' => now(),
        'sale_lines' => [
            ['description' => 'Formaggio Alpino', 'quantity' => 2.5, 'unit_measure' => 'kg', 'line_total' => 50.00, 'lot_number' => 'LOT-1'],
        ],
    ]);

    $payload = app(ThermalPrintPayloadBuilderService::class)->buildForVisit($visit, $van, [
        'company_name' => 'Caseificio Ticino SA',
        'customer_name' => 'Ristorante Lugano',
    ]);

    expect($payload->paperWidthMm)->toBe('104mm')
        ->and($payload->escposCommands)->toContain('Caseificio Ticino SA')
        ->and($payload->zplCommands)->toContain('^XA')
        ->and($payload->zplCommands)->toContain('FV-TI01/2026/00001')
        ->and($payload->zplCommands)->toContain('^XZ');
});
