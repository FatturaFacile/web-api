<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\DebtCollection\Models\Batch;
use Modules\DebtCollection\Models\Dossier;
use Modules\DebtCollection\Models\Exclusion;
use Modules\DebtCollection\Models\Notice;
use Modules\DebtCollection\Models\PaymentPlan;

/**
 * Read queries of the module's lists.
 */
final class ListingService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Notice>
     */
    public function notices(array $filters, int $perPage): LengthAwarePaginator
    {
        return Notice::query()->with(['customer', 'lines', 'receipts'])
            ->when(! empty($filters['customer_id']), fn ($query) => $query->where('customer_id', $filters['customer_id']))
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['level']), fn ($query) => $query->where('level', (int) $filters['level']))
            ->when(! empty($filters['kind']), fn ($query) => $query->where('kind', $filters['kind']))
            ->when(! empty($filters['batch_id']), fn ($query) => $query->where('batch_id', $filters['batch_id']))
            ->orderByDesc('issued_on')->orderByDesc('number')
            ->paginate($perPage);
    }

    /**
     * @return LengthAwarePaginator<int, Batch>
     */
    public function batches(int $perPage): LengthAwarePaginator
    {
        return Batch::query()->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * @return LengthAwarePaginator<int, Dossier>
     */
    public function dossiers(?string $customerId, int $perPage): LengthAwarePaginator
    {
        return Dossier::query()->with('customer')->when($customerId !== null, fn ($query) => $query->where('customer_id', $customerId))->orderByDesc('generated_at')->paginate($perPage);
    }

    /**
     * @return Collection<int, Exclusion>
     */
    public function exclusions(?string $customerId, bool $activeOnly): Collection
    {
        return Exclusion::query()->with('customer')
            ->when($customerId !== null, fn ($query) => $query->where('customer_id', $customerId))
            ->when($activeOnly, fn ($query) => $query->whereNull('released_at'))
            ->orderByDesc('created_at')->get();
    }

    /**
     * @return Collection<int, PaymentPlan>
     */
    public function plans(?string $customerId): Collection
    {
        return PaymentPlan::query()->with('customer')->when($customerId !== null, fn ($query) => $query->where('customer_id', $customerId))->orderByDesc('created_at')->get();
    }
}
