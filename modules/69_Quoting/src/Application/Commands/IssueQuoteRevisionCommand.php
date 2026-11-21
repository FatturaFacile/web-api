<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Commands;

final readonly class IssueQuoteRevisionCommand
{
    public function __construct(
        public string $quoteId,
        public ?string $userId,
        public ?int $validityDays = null,
        public bool $usePin = false,
        public bool $overrideMarginGuardrail = false,
    ) {}
}
