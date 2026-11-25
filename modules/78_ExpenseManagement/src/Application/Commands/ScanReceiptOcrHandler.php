<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Commands;

use Modules\ExpenseManagement\Services\ReceiptService;

final class ScanReceiptOcrHandler
{
    public function __construct(private readonly ReceiptService $receipts) {}

    /**
     * @return array<string, mixed> receipt id, SHA-256, OCR fields with confidence and the duplicate warnings
     */
    public function handle(ScanReceiptOcrCommand $command): array
    {
        return $this->receipts->scan($command->file, $command->clientText, $command->tenantId, $command->userId);
    }
}
