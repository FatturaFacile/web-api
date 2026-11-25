<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Contracts;

use Modules\ExpenseManagement\Domain\ValueObjects\OcrResult;

/**
 * Reads a receipt. A provider backed by an OCR engine replaces the deterministic parser through the container binding;
 * `$clientText` is the text a mobile client already recognised on the device, when it sent one.
 */
interface ReceiptOcrProviderInterface
{
    public function extract(string $contents, string $mimeType, string $fileName, ?string $clientText = null): OcrResult;
}
