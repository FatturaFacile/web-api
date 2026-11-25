<?php

declare(strict_types=1);

use Modules\Returns\Services\ReturnEligibilityService;

it('generates deterministic CODE128 RMA barcodes for return numbers', function (): void {
    $service = app(ReturnEligibilityService::class);
    $barcodeA = $service->generateBarcode('CRMA-2027-0001');
    $barcodeB = $service->generateBarcode('CRMA-2027-0001');
    $barcodeC = $service->generateBarcode('CRMA-2027-0002');

    expect($barcodeA)->toBe($barcodeB)
        ->and($barcodeA)->not->toBe($barcodeC)
        ->and($barcodeA)->toStartWith('RMA-BC-CRMA20270001-');
});
