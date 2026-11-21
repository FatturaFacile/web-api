<?php

declare(strict_types=1);

namespace App\Http\Resources\Procurement\PurchaseRequisition;

use App\Domain\Procurement\PurchaseRequisition\ValueObjects\BudgetCheckResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BudgetCheckResult
 */
final class BudgetPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BudgetCheckResult $result */
        $result = $this->resource;

        return $result->toSnapshotArray();
    }
}
