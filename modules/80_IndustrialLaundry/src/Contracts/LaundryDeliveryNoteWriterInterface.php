<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Contracts;

use Illuminate\Support\Carbon;
use Modules\IndustrialLaundry\Domain\Enums\ScanDirection;

/**
 * Draws up the delivery note (DDT) of a roll cage that passed a tunnel gate: a pick-up note for the soiled linen received, a
 * delivery note for the clean linen sent back.
 */
interface LaundryDeliveryNoteWriterInterface
{
    /**
     * @param  array<string, int>  $piecesByArticleType  Article type value => number of pieces.
     * @return array{id: string, number: string}
     */
    public function write(
        string $tenantId,
        string $contactId,
        ScanDirection $direction,
        array $piecesByArticleType,
        ?string $grossWeightKg,
        ?string $netWeightKg,
        string $rollCageBarcode,
        Carbon $at,
        ?string $userId,
    ): array;
}
