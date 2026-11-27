<?php

declare(strict_types=1);

use Modules\Esterometro\Domain\Enums\SelfInvoiceDocumentType;
use Modules\Esterometro\Domain\Enums\TransactionNature;
use Modules\Esterometro\Services\SelfInvoiceClassifierService;

it('classifies domestic reverse charge N6.x invoices as TD16', function (string $nature): void {
    $classifier = new SelfInvoiceClassifierService;

    $result = $classifier->classify(
        tenantId: null,
        supplierCountryCode: 'IT',
        transactionNature: TransactionNature::DomesticRc,
        sourceVatNature: $nature,
    );

    expect($result['requires_self_invoice'])->toBeTrue()
        ->and($result['suggested_document_type'])->toBe(SelfInvoiceDocumentType::TD16)
        ->and($result['document_type'])->toBe(SelfInvoiceDocumentType::TD16)
        ->and($result['supplier_region'])->toBe('IT')
        ->and($result['is_type_overridden'])->toBeFalse();
})->with(['N6.1', 'N6.2', 'N6.3', 'N6.4', 'N6.5', 'N6.6', 'N6.7', 'N6.8', 'N6.9']);

it('classifies EU and Extra-EU services as TD17', function (string $country, string $expectedRegion): void {
    $classifier = new SelfInvoiceClassifierService;

    $result = $classifier->classify(
        tenantId: null,
        supplierCountryCode: $country,
        transactionNature: TransactionNature::Services,
    );

    expect($result['requires_self_invoice'])->toBeTrue()
        ->and($result['document_type'])->toBe(SelfInvoiceDocumentType::TD17)
        ->and($result['supplier_region'])->toBe($expectedRegion);
})->with([
    ['DE', 'EU'],
    ['FR', 'EU'],
    ['CH', 'EXTRA_EU'],
    ['US', 'EXTRA_EU'],
]);

it('classifies EU goods acquisitions as TD18', function (): void {
    $classifier = new SelfInvoiceClassifierService;

    $result = $classifier->classify(
        tenantId: null,
        supplierCountryCode: 'DE',
        transactionNature: TransactionNature::Goods,
    );

    expect($result['document_type'])->toBe(SelfInvoiceDocumentType::TD18)
        ->and($result['supplier_region'])->toBe('EU');
});

it('classifies foreign goods already in Italy as TD19 and skips when customs import bill is present', function (): void {
    $classifier = new SelfInvoiceClassifierService;

    $inItaly = $classifier->classify(
        tenantId: null,
        supplierCountryCode: 'CH',
        transactionNature: TransactionNature::GoodsInItaly,
    );

    expect($inItaly['requires_self_invoice'])->toBeTrue()
        ->and($inItaly['document_type'])->toBe(SelfInvoiceDocumentType::TD19);

    $customs = $classifier->classify(
        tenantId: null,
        supplierCountryCode: 'US',
        transactionNature: TransactionNature::Goods,
        hasCustomsImportBill: true,
    );

    expect($customs['requires_self_invoice'])->toBeFalse()
        ->and($customs['document_type'])->toBeNull()
        ->and($customs['compliance_warnings'])->not->toBeEmpty();
});

it('classifies San Marino purchases as TD28 and regularization as TD20 with manual override warning', function (): void {
    $classifier = new SelfInvoiceClassifierService;

    $sm = $classifier->classify(
        tenantId: null,
        supplierCountryCode: 'SM',
        transactionNature: TransactionNature::SanMarino,
    );
    expect($sm['document_type'])->toBe(SelfInvoiceDocumentType::TD28)
        ->and($sm['supplier_region'])->toBe('SM');

    $td20 = $classifier->classify(
        tenantId: null,
        supplierCountryCode: 'IT',
        transactionNature: TransactionNature::Regularization,
        regularizationTrigger: 'omitted_4_months',
    );
    expect($td20['document_type'])->toBe(SelfInvoiceDocumentType::TD20);

    $overridden = $classifier->classify(
        tenantId: null,
        supplierCountryCode: 'DE',
        transactionNature: TransactionNature::Services,
        overrideType: SelfInvoiceDocumentType::TD19,
    );
    expect($overridden['is_type_overridden'])->toBeTrue()
        ->and($overridden['suggested_document_type'])->toBe(SelfInvoiceDocumentType::TD17)
        ->and($overridden['document_type'])->toBe(SelfInvoiceDocumentType::TD19)
        ->and(collect($overridden['compliance_warnings'])->pluck('code'))->toContain('MANUAL_TYPE_OVERRIDE');
});
