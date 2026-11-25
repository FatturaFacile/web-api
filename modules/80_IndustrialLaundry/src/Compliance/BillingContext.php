<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Compliance;

use Illuminate\Support\Carbon;
use Modules\IndustrialLaundry\Models\ParContract;

/**
 * The facts of a billing period a billing strategy decorates the invoice with.
 */
final readonly class BillingContext
{
    /**
     * @param  list<string>  $deliveryNoteNumbers  DDT numbers of the period (deferred invoice references).
     * @param  array{out: int, back: int, at_customer: int}  $rollCages  Roll cages sent clean, received soiled and still at the customer.
     */
    public function __construct(
        public ParContract $contract,
        public Carbon $periodStart,
        public Carbon $periodEnd,
        public string $netWeightKg,
        public int $washedPieces,
        public array $deliveryNoteNumbers,
        public array $rollCages,
    ) {}
}
