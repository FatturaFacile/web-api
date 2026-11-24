<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Handlers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\DigitalPreservation\Application\Queries\ListPreservationBatchesQuery;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;

final readonly class ListPreservationBatchesHandler
{
    /**
     * @return LengthAwarePaginator<int, PreservationBatch>
     */
    public function handle(ListPreservationBatchesQuery $query): LengthAwarePaginator
    {
        return PreservationBatch::query()
            ->when($query->status, fn ($q, $v) => $q->where('status', $v))
            ->when($query->jurisdiction, fn ($q, $v) => $q->where('jurisdiction', $v))
            ->when($query->documentCategory, fn ($q, $v) => $q->where('document_category', $v))
            ->when($query->search, fn ($q, $v) => $q->where(fn ($w) => $w->whereRaw('LOWER(batch_number) LIKE ?', ['%'.mb_strtolower($v).'%'])->orWhere('pdv_sha256', 'like', mb_strtolower($v).'%')))
            ->orderByDesc('created_at')->orderByDesc('batch_number')
            ->paginate(max(1, min($query->perPage, 100)));
    }
}
