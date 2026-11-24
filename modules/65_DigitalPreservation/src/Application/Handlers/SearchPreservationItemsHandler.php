<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Handlers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\DigitalPreservation\Application\Queries\SearchPreservationItemsQuery;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatchItem;

final readonly class SearchPreservationItemsHandler
{
    /**
     * @return LengthAwarePaginator<int, PreservationBatchItem>
     */
    public function handle(SearchPreservationItemsQuery $query): LengthAwarePaginator
    {
        $like = static fn (string $value): string => '%'.mb_strtolower($value).'%';

        return PreservationBatchItem::query()->with('batch')
            ->whereHas('batch', function (Builder $batch) use ($query): void {
                $batch->where('status', '!=', 'draft')
                    ->when($query->documentCategory, fn ($q, $v) => $q->where('document_category', $v))
                    ->when($query->jurisdiction, fn ($q, $v) => $q->where('jurisdiction', $v));
            })
            ->when($query->documentNumber, fn ($q, $v) => $q->whereRaw('LOWER(document_number) LIKE ?', [$like($v)]))
            ->when($query->counterparty, fn ($q, $v) => $q->whereRaw('LOWER(counterparty_name) LIKE ?', [$like($v)]))
            ->when($query->taxId, fn ($q, $v) => $q->whereRaw('LOWER(counterparty_tax_id) LIKE ?', [$like($v)]))
            ->when($query->sha256, fn ($q, $v) => $q->where('sha256_hash', mb_strtolower(trim($v))))
            ->when($query->dateFrom, fn ($q, $v) => $q->where('document_date', '>=', $v))
            ->when($query->dateTo, fn ($q, $v) => $q->where('document_date', '<=', $v))
            ->when($query->search, fn ($q, $v) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(document_number) LIKE ?', [$like($v)])
                ->orWhereRaw('LOWER(counterparty_name) LIKE ?', [$like($v)])
                ->orWhereRaw('LOWER(counterparty_tax_id) LIKE ?', [$like($v)])
                ->orWhere('document_uuid', trim($v))
                ->orWhere('sha256_hash', mb_strtolower(trim($v)))))
            ->orderByDesc('document_date')->orderBy('sequence')
            ->paginate(max(1, min($query->perPage, 100)));
    }
}
