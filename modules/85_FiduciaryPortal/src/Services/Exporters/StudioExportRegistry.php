<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Services\Exporters;

use InvalidArgumentException;
use Modules\FiduciaryPortal\Domain\Enums\StudioExportFormat;

final readonly class StudioExportRegistry
{
    /** @var array<string, StudioExportStrategyInterface> */
    private array $strategies;

    public function __construct(
        AbacusXmlExporter $abacusXml,
        AbacusAsciiExporter $abacusAscii,
        TopalExporter $topal,
        Sage50ExtraExporter $sage50Extra,
        SwissFiduciaryUniversalExporter $swissUniversal,
        TeamSystemExporter $teamSystem,
        ZucchettiExporter $zucchetti,
        ProfisExporter $profis,
        DatevKoinosExporter $datevKoinos,
        UniversalCsvExporter $universalCsv,
    ) {
        $all = [
            $abacusXml,
            $abacusAscii,
            $topal,
            $sage50Extra,
            $swissUniversal,
            $teamSystem,
            $zucchetti,
            $profis,
            $datevKoinos,
            $universalCsv,
        ];

        $indexed = [];
        foreach ($all as $strategy) {
            $indexed[$strategy->format()->value] = $strategy;
        }
        $this->strategies = $indexed;
    }

    public function resolve(StudioExportFormat $format): StudioExportStrategyInterface
    {
        return $this->strategies[$format->value]
            ?? throw new InvalidArgumentException("Unsupported studio export format [{$format->value}].");
    }
}
