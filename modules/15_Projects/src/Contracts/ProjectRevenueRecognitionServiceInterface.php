<?php

declare(strict_types=1);

namespace Modules\Projects\Contracts;

use Modules\Projects\DTOs\RevenueRecognitionDTO;
use Modules\Projects\Models\ProjectRevenueRecognition;

/**
 * Revenue recognition runs of projects (percentage of completion, completed contract).
 */
interface ProjectRevenueRecognitionServiceInterface
{
    public function calculateAndRecognize(RevenueRecognitionDTO $dto): ProjectRevenueRecognition;
}
