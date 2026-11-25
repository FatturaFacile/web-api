<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class ReverseLogisticsReturnProcessedEvent
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $returnId,
        public string $bStockGrade,
        public bool $art26VatRecoverable,
        public ?string $creditNoteDocumentId = null,
    ) {}
}
