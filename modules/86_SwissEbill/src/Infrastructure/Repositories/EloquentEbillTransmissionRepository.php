<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Infrastructure\Repositories;

use Illuminate\Support\Collection;
use Modules\SwissEbill\Domain\Enums\EbillTransmissionStatus;
use Modules\SwissEbill\Domain\Repositories\EbillTransmissionRepositoryInterface;
use Modules\SwissEbill\Models\EbillTransmission;

final class EloquentEbillTransmissionRepository implements EbillTransmissionRepositoryInterface
{
    public function find(string $id): ?EbillTransmission
    {
        return EbillTransmission::query()->find($id);
    }

    public function findOrFail(string $id): EbillTransmission
    {
        return EbillTransmission::query()->findOrFail($id);
    }

    public function findBySixTransactionId(string $sixTransactionId): ?EbillTransmission
    {
        return EbillTransmission::query()->where('six_transaction_id', $sixTransactionId)->first();
    }

    public function latestForInvoice(string $invoiceId): ?EbillTransmission
    {
        return EbillTransmission::query()->where('invoice_id', $invoiceId)->latest('created_at')->latest('id')->first();
    }

    public function activeForInvoice(string $invoiceId): ?EbillTransmission
    {
        return EbillTransmission::query()
            ->where('invoice_id', $invoiceId)
            ->whereNotIn('status', [
                EbillTransmissionStatus::RejectedByDebtor->value,
                EbillTransmissionStatus::Cancelled->value,
                EbillTransmissionStatus::FailedTechnical->value,
            ])
            ->latest('created_at')
            ->first();
    }

    public function inFlight(): Collection
    {
        return EbillTransmission::query()
            ->whereIn('status', array_map(static fn (EbillTransmissionStatus $s): string => $s->value, EbillTransmissionStatus::inFlight()))
            ->whereNotNull('six_transaction_id')
            ->get();
    }

    public function staleQueued(int $minutes): Collection
    {
        return EbillTransmission::query()
            ->where('status', EbillTransmissionStatus::Queued->value)
            ->where('updated_at', '<=', now()->subMinutes($minutes))
            ->get();
    }
}
