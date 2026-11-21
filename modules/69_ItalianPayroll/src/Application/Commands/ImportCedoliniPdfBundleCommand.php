<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Application\Commands;

final readonly class ImportCedoliniPdfBundleCommand
{
    public function __construct(public string $periodId, public string $pdfBinary, public ?string $actorId) {}
}
