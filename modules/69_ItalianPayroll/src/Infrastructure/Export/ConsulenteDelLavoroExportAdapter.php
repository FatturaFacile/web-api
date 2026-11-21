<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Export;

use Modules\ItalianPayroll\Domain\Enums\ConsultantFormat;

/**
 * Strategy that serializes the validated attendance of a month in the file layout of one consultant software.
 */
interface ConsulenteDelLavoroExportAdapter
{
    public function format(): ConsultantFormat;

    /**
     * @param  list<ExportRow>  $rows
     */
    public function render(int $year, int $month, string $companyCode, array $rows): ExportFile;
}
