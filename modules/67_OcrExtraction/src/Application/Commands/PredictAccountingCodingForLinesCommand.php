<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Application\Commands;

/**
 * Suggests the GL account, VAT code and cost center of every extracted line from what the vendor was coded with before.
 */
final readonly class PredictAccountingCodingForLinesCommand
{
    public function __construct(public string $jobId, public string $tenantId, public string $vendorId) {}
}
