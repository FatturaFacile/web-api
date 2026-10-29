<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Actions;

use Modules\Manufacturing\Contracts\QualityManagementServiceInterface;
use Modules\Manufacturing\DTOs\RecordQualityInspectionDTO;
use Modules\Manufacturing\Models\MfgQualityInspection;

final readonly class RecordInspectionAction
{
    public function __construct(
        private QualityManagementServiceInterface $qmService
    ) {}

    public function execute(RecordQualityInspectionDTO $dto): MfgQualityInspection
    {
        return $this->qmService->executeInspection($dto);
    }
}
