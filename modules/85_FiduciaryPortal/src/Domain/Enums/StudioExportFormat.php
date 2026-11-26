<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Domain\Enums;

/**
 * Supported studio accounting export formats (Switzerland & Italy).
 */
enum StudioExportFormat: string
{
    case AbacusXml = 'abacus_xml';
    case AbacusAscii = 'abacus_ascii';
    case Topal = 'topal';
    case Sage50Extra = 'sage50_extra';
    case SwissFiduciaryUniversal = 'swiss_fiduciary_universal';
    case TeamSystem = 'teamsystem';
    case Zucchetti = 'zucchetti';
    case Profis = 'profis';
    case DatevKoinos = 'datev_koinos';
    case UniversalCsv = 'universal_csv';

    public function jurisdiction(): string
    {
        return match ($this) {
            self::AbacusXml,
            self::AbacusAscii,
            self::Topal,
            self::Sage50Extra,
            self::SwissFiduciaryUniversal => 'CH',
            self::TeamSystem,
            self::Zucchetti,
            self::Profis,
            self::DatevKoinos => 'IT',
            self::UniversalCsv => 'ALL',
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::AbacusXml => 'xml',
            self::AbacusAscii => 'txt',
            self::Sage50Extra => 'taf',
            default => 'csv',
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::AbacusXml => 'application/xml; charset=UTF-8',
            self::AbacusAscii, self::Sage50Extra => 'text/plain; charset=UTF-8',
            self::DatevKoinos => 'text/csv; charset=Windows-1252',
            default => 'text/csv; charset=UTF-8',
        };
    }

    public function labelKey(): string
    {
        return "fiduciaryPortal.exportFormat.{$this->value}.label";
    }

    public function descriptionKey(): string
    {
        return "fiduciaryPortal.exportFormat.{$this->value}.description";
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        return [
            'code' => $this->value,
            'jurisdiction' => $this->jurisdiction(),
            'extension' => $this->extension(),
            'mime_type' => $this->mimeType(),
            'label_key' => $this->labelKey(),
            'description_key' => $this->descriptionKey(),
        ];
    }
}
