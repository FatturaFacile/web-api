<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Export;

use Modules\ItalianPayroll\Domain\Enums\ConsultantFormat;

final class ExportAdapterRegistry
{
    /** @var array<string, ConsulenteDelLavoroExportAdapter> */
    private array $adapters = [];

    public function __construct()
    {
        foreach ([new ZucchettiExportAdapter, new TeamSystemExportAdapter, new InazExportAdapter, new GisExportAdapter, new GenericCsvExportAdapter] as $adapter) {
            $this->adapters[$adapter->format()->value] = $adapter;
        }
    }

    public function for(ConsultantFormat $format): ConsulenteDelLavoroExportAdapter
    {
        return $this->adapters[$format->value];
    }
}
