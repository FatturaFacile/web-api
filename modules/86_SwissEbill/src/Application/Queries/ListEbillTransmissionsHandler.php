<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\SwissEbill\Models\EbillTransmission;

final class ListEbillTransmissionsHandler
{
    /**
     * @return LengthAwarePaginator<int, EbillTransmission>
     */
    public function handle(ListEbillTransmissionsQuery $query): LengthAwarePaginator
    {
        $f = $query->filters;

        return EbillTransmission::query()
            ->with(['invoice:id,invoice_number,date,due_date,total_gross,currency', 'contact:id,company_name,first_name,last_name'])
            ->when($f['ebill_status'] ?? null, fn ($rows, $status) => $rows->where('status', $status))
            ->when($f['channel_type'] ?? null, fn ($rows, $channel) => $rows->where('channel_type', $channel))
            ->when($f['customer_id'] ?? null, fn ($rows, $customer) => $rows->where('contact_id', $customer))
            ->when($f['invoice_id'] ?? null, fn ($rows, $invoice) => $rows->where('invoice_id', $invoice))
            ->when($f['from'] ?? null, fn ($rows, $from) => $rows->where('created_at', '>=', $from))
            ->when($f['to'] ?? null, fn ($rows, $to) => $rows->where('created_at', '<=', $to.' 23:59:59'))
            ->when($f['search'] ?? null, fn ($rows, $term) => $rows->where(function ($inner) use ($term): void {
                $like = '%'.mb_strtolower((string) $term).'%';
                $inner->whereRaw('LOWER(six_transaction_id) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(recipient_id) LIKE ?', [$like])
                    ->orWhereHas('invoice', fn ($invoice) => $invoice->whereRaw('LOWER(invoice_number) LIKE ?', [$like]));
            }))
            ->latest('created_at')
            ->latest('id')
            ->paginate($query->perPage);
    }
}
