<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Exceptions;

use RuntimeException;

/**
 * An atomic import met a row it could not save: the surrounding transaction rolls back and the row is reported.
 */
final class ImportAborted extends RuntimeException
{
    /**
     * @param  list<array{line: int, sku: string|null, field: string, message: string}>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('catalog import aborted');
    }
}
