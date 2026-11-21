<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Commands;

final readonly class CreateNewQuoteRevisionCommand
{
    public function __construct(
        public string $quoteId,
        public ?string $userId,
    ) {}
}
