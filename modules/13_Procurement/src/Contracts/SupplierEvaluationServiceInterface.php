<?php

declare(strict_types=1);

namespace Modules\Procurement\Contracts;

use Modules\Procurement\DTOs\EvaluateSupplierDTO;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierEvaluation;
use Modules\Procurement\Models\SupplierScorecard;

interface SupplierEvaluationServiceInterface
{
    public function recordEvaluation(EvaluateSupplierDTO $dto): SupplierEvaluation;

    public function generateQuarterlyScorecard(Supplier $supplier, int $year, int $quarter): SupplierScorecard;
}
