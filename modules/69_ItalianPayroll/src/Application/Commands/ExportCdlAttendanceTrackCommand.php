<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Application\Commands;

use Modules\ItalianPayroll\Domain\Enums\ConsultantFormat;

final readonly class ExportCdlAttendanceTrackCommand
{
    public function __construct(public string $periodId, public ConsultantFormat $format, public string $companyCode, public ?string $actorId) {}
}
