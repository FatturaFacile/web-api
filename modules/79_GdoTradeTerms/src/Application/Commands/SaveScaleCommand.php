<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

final readonly class SaveScaleCommand
{
    /**
     * @param  array<int|string, mixed>  $data
     */
    public function __construct(
        public string $contractId,
        public array $data,
        public ?string $userId,
        public ?string $scaleId = null,
    ) {}
}
