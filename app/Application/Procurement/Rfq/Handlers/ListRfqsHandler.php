<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Handlers;

use App\Application\Procurement\Rfq\Queries\ListRfqsQuery;
use App\Domain\Procurement\Rfq\Enums\BidStatus;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Returns a paginated, filterable list of RFQs for the active tenant.
 */
final class ListRfqsHandler
{
    /**
     * @return LengthAwarePaginator<int, RequestForQuotation>
     */
    public function handle(ListRfqsQuery $query): LengthAwarePaginator
    {
        $builder = RequestForQuotation::query()
            ->with(['invitations.supplier', 'buyer'])
            ->withCount([
                'lines as lines_count',
                'invitations as invited_count',
                'bids as bids_received_count' => static fn ($q) => $q->whereIn('status', [
                    BidStatus::Submitted->value,
                    BidStatus::Revised->value,
                    BidStatus::Received->value,
                    BidStatus::Awarded->value,
                    BidStatus::Rejected->value,
                ]),
            ])
            ->orderByDesc('created_at');

        if ($query->status !== null && trim($query->status) !== '') {
            $builder->where('status', trim($query->status));
        }

        if ($query->buyerUserId !== null && trim($query->buyerUserId) !== '') {
            $builder->where('buyer_user_id', trim($query->buyerUserId));
        }

        if ($query->dateFrom !== null && trim($query->dateFrom) !== '') {
            $builder->whereDate('response_deadline', '>=', trim($query->dateFrom));
        }

        if ($query->dateTo !== null && trim($query->dateTo) !== '') {
            $builder->whereDate('response_deadline', '<=', trim($query->dateTo));
        }

        if ($query->search !== null && trim($query->search) !== '') {
            $term = '%'.mb_strtolower(trim($query->search)).'%';
            $builder->where(static function ($q) use ($term): void {
                $q->whereRaw('LOWER(title) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(rfq_number) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$term]);
            });
        }

        $perPage = max(1, min(100, $query->perPage));

        return $builder->gridPaginate($perPage);
    }
}
