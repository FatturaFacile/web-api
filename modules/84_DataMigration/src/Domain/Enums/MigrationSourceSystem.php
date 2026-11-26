<?php

declare(strict_types=1);

namespace Modules\DataMigration\Domain\Enums;

enum MigrationSourceSystem: string
{
    case FattureInCloud = 'FattureInCloud';
    case DipendentiInCloud = 'DipendentiInCloud';
    case Bexio = 'Bexio';
    case Abacus = 'Abacus';
    case BananaAccounting = 'BananaAccounting';
    case DaneaEasyfatt = 'DaneaEasyfatt';
    case TeamSystem = 'TeamSystem';
    case Zucchetti = 'Zucchetti';
    case Odoo = 'Odoo';
    case FatturaPaXmlZipArchive = 'FatturaPaXmlZipArchive';
    case GenericCsvXlsx = 'GenericCsvXlsx';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function jurisdiction(): string
    {
        return match ($this) {
            self::Bexio, self::Abacus, self::BananaAccounting => 'CH',
            self::FattureInCloud, self::DipendentiInCloud, self::DaneaEasyfatt, self::TeamSystem, self::Zucchetti, self::FatturaPaXmlZipArchive => 'IT',
            self::Odoo, self::GenericCsvXlsx => 'CROSS_BORDER',
        };
    }

    public function defaultCurrency(): string
    {
        return $this->jurisdiction() === 'CH' ? 'CHF' : 'EUR';
    }

    /**
     * @return list<string>
     */
    public function supportedExtensions(): array
    {
        return match ($this) {
            self::FatturaPaXmlZipArchive => ['zip', 'xml'],
            default => ['csv', 'xlsx', 'xml', 'zip'],
        };
    }

    /**
     * @return list<MigrationTargetEntity>
     */
    public function defaultEntities(): array
    {
        return match ($this) {
            self::DipendentiInCloud => [MigrationTargetEntity::Employees],
            self::FatturaPaXmlZipArchive => [
                MigrationTargetEntity::Customers,
                MigrationTargetEntity::Vendors,
                MigrationTargetEntity::Products,
                MigrationTargetEntity::HistoricalInvoices,
            ],
            self::BananaAccounting => [
                MigrationTargetEntity::ChartOfAccounts,
                MigrationTargetEntity::OpeningGlBalances,
                MigrationTargetEntity::Customers,
                MigrationTargetEntity::Vendors,
            ],
            self::Bexio, self::Abacus => [
                MigrationTargetEntity::ChartOfAccounts,
                MigrationTargetEntity::Customers,
                MigrationTargetEntity::Vendors,
                MigrationTargetEntity::Products,
                MigrationTargetEntity::OpeningGlBalances,
                MigrationTargetEntity::OpenArApItems,
            ],
            default => [
                MigrationTargetEntity::Customers,
                MigrationTargetEntity::Vendors,
                MigrationTargetEntity::Products,
                MigrationTargetEntity::HistoricalInvoices,
            ],
        };
    }
}
