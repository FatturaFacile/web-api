<?php

declare(strict_types=1);

namespace Modules\Procurement\Actions;

use Modules\Procurement\Contracts\SupplierEvaluationServiceInterface;
use Modules\Procurement\DTOs\EvaluateSupplierDTO;
use Modules\Procurement\Models\SupplierEvaluation;

final class EvaluateSupplierPerformanceAction
{
    public function __construct(
        private readonly SupplierEvaluationServiceInterface $evaluationService
    ) {}

    public function execute(EvaluateSupplierDTO $dto): SupplierEvaluation
    {
        return $this->evaluationService->recordEvaluation($dto);
    }
}
