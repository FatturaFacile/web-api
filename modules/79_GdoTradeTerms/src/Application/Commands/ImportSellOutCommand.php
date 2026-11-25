<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

final readonly class ImportSellOutCommand
{
    /**
     * @param  array<int|string, mixed>  $rows
     */
    public function __construct(
        public string $contractId,
        public array $rows,
        public string $source,
        public ?string $userId,
    ) {}
}
