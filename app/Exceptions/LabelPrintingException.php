<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a label sheet cannot be produced, e.g. because some products cannot be encoded as the chosen barcode.
 */
class LabelPrintingException extends RuntimeException
{
    /**
     * @param  array<int, array{product_id: string, sku: string, reason: string}>  $failures
     */
    public function __construct(public readonly array $failures)
    {
        parent::__construct(__('labels.errors.products_not_encodable'));
    }
}
