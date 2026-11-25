<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Exceptions;

use RuntimeException;

final class VanSellingException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $errorKey,
        public readonly int $statusCode = 422,
        public readonly array $context = [],
        ?string $message = null,
    ) {
        parent::__construct($message ?? __('van_selling.errors.'.$errorKey, $context));
    }
}
