<?php

declare(strict_types=1);

use Database\Seeders\Translations\DataMigrationFrontendTranslations;
use Modules\DataMigration\Domain\Enums\MigrationSourceSystem;
use Modules\DataMigration\Domain\Enums\MigrationTargetEntity;
use Modules\DataMigration\Services\ConnectorPresetRegistry;
use Modules\DataMigration\Services\SmartColumnMappingDetector;

test('connector preset registry covers all 11 source ERPs and 8 target entities', function (): void {
    $registry = new ConnectorPresetRegistry;
    $connectors = $registry->connectorsMetadata();
    $entities = $registry->entitySchemas();

    expect($connectors)->toHaveCount(11)
        ->and($entities)->toHaveCount(8)
        ->and(array_column($connectors, 'code'))->toContain(
            'FattureInCloud',
            'DipendentiInCloud',
            'Bexio',
            'Abacus',
            'BananaAccounting',
            'DaneaEasyfatt',
            'TeamSystem',
            'Zucchetti',
            'Odoo',
            'FatturaPaXmlZipArchive',
            'GenericCsvXlsx',
        );
});

test('smart column mapping detector infers Swiss Bexio headers and formats', function (): void {
    $registry = new ConnectorPresetRegistry;
    $detector = new SmartColumnMappingDetector($registry);

    $result = $detector->detect(
        MigrationSourceSystem::Bexio,
        [MigrationTargetEntity::Customers->value],
        ['Firma', 'Land', 'UID', 'QR-IBAN', 'PLZ', 'Ort'],
        [MigrationTargetEntity::Customers->value => ['Firma', 'Land', 'UID', 'QR-IBAN', 'PLZ', 'Ort']],
        [['Firma' => 'Helvetia AG', 'Land' => 'CH', 'UID' => 'CHE-105.805.239', 'QR-IBAN' => 'CH4431999123000889012', 'PLZ' => '6900', 'Ort' => 'Lugano']],
    );

    $customerMap = $result['mappings_by_entity'][MigrationTargetEntity::Customers->value];

    expect($customerMap['company_name']['source_column'])->toBe('Firma')
        ->and($customerMap['uid_number']['source_column'])->toBe('UID')
        ->and($customerMap['qr_iban']['source_column'])->toBe('QR-IBAN')
        ->and($customerMap['company_name']['confidence'])->toBeGreaterThanOrEqual(0.9)
        ->and($result['value_transformations']['currency_default'])->toBe('CHF');
});

test('5-locale frontend translation dictionary has full parity across it-CH, de-CH, fr-CH, en, and it-IT', function (): void {
    $locales = ['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT'];
    $expectedKeys = array_keys(DataMigrationFrontendTranslations::definitions());

    foreach ($locales as $locale) {
        $dict = DataMigrationFrontendTranslations::all($locale);
        expect(array_keys($dict))->toBe($expectedKeys);
        foreach ($dict as $value) {
            expect($value)->not->toBe('');
        }
    }
});
