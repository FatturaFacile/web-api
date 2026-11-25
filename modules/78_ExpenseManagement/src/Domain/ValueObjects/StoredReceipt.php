<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Domain\ValueObjects;

final readonly class StoredReceipt
{
    public function __construct(
        public string $disk,
        public string $path,
        public ?string $documentId = null,
    ) {}
}
