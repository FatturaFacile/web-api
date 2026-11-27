<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Repositories;

use Illuminate\Support\Collection;
use Modules\SwissEbill\Models\EbillTransmission;

interface EbillTransmissionRepositoryInterface
{
    public function find(string $id): ?EbillTransmission;

    public function findOrFail(string $id): EbillTransmission;

    public function findBySixTransactionId(string $sixTransactionId): ?EbillTransmission;

    /**
     * The latest transmission of an invoice, whatever its status.
     */
    public function latestForInvoice(string $invoiceId): ?EbillTransmission;

    /**
     * The transmission of an invoice that is alive: neither rejected, cancelled nor failed.
     */
    public function activeForInvoice(string $invoiceId): ?EbillTransmission;

    /**
     * Transmissions the debtor may still see (transmitted, delivered, approved) that carry a gateway transaction id.
     *
     * @return Collection<int, EbillTransmission>
     */
    public function inFlight(): Collection;

    /**
     * Queued transmissions older than the given minutes (their job got lost): to be dispatched again.
     *
     * @return Collection<int, EbillTransmission>
     */
    public function staleQueued(int $minutes): Collection;
}
