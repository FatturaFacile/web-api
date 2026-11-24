<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Handlers;

use App\Application\Procurement\PurchaseRequisition\Queries\GetPurchaseRequisitionsQuery;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Paginated, dialect-agnostic query handler for listing Purchase Requisitions.
 */
final readonly class GetPurchaseRequisitionsHandler
{
    /**
     * @return LengthAwarePaginator<int, PurchaseRequisition>
     */
    public function handle(GetPurchaseRequisitionsQuery $query): LengthAwarePaginator
    {
        $builder = PurchaseRequisition::query()
            ->where('tenant_id', $query->tenantId)
            ->with(['lines', 'requester', 'project', 'documentLinks']);

        if ($query->status !== null && trim($query->status) !== '') {
            $builder->where('status', trim($query->status));
        }

        if ($query->costCenterId !== null && trim($query->costCenterId) !== '') {
            $costCenterId = trim($query->costCenterId);
            $builder->where(static function (Builder $sub) use ($costCenterId): void {
                $sub->where('cost_center_id', $costCenterId)
                    ->orWhereHas('lines', static fn (Builder $line) => $line->where('cost_center_id', $costCenterId));
            });
        }

        if ($query->projectId !== null && trim($query->projectId) !== '') {
            $projectId = trim($query->projectId);
            $builder->where(static function (Builder $sub) use ($projectId): void {
                $sub->where('project_id', $projectId)
                    ->orWhereHas('lines', static fn (Builder $line) => $line->where('project_id', $projectId));
            });
        }

        if ($query->urgency !== null && trim($query->urgency) !== '') {
            $builder->where('urgency', trim($query->urgency));
        }

        if ($query->requesterId !== null && trim($query->requesterId) !== '') {
            $requesterId = trim($query->requesterId);
            $builder->where(static function (Builder $sub) use ($requesterId): void {
                $sub->where('requester_user_id', $requesterId)
                    ->orWhere('requestor_user_id', $requesterId);
            });
        }

        if ($query->dateFrom !== null && trim($query->dateFrom) !== '') {
            $builder->whereDate('created_at', '>=', trim($query->dateFrom));
        }

        if ($query->dateTo !== null && trim($query->dateTo) !== '') {
            $builder->whereDate('created_at', '<=', trim($query->dateTo));
        }

        if ($query->search !== null && trim($query->search) !== '') {
            $pattern = '%'.strtolower(trim($query->search)).'%';
            $builder->where(static function (Builder $sub) use ($pattern): void {
                $sub->whereRaw('LOWER(requisition_number) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(title) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(COALESCE(economic_justification, \'\')) LIKE ?', [$pattern])
                    ->orWhereHas('lines', static fn (Builder $line) => $line->whereRaw('LOWER(description) LIKE ?', [$pattern]));
            });
        }

        $perPage = max(1, min(100, $query->perPage));
        $page = max(1, $query->page);

        return $builder
            ->orderByDesc('created_at')
            ->paginate(perPage: $perPage, page: $page);
    }
}
