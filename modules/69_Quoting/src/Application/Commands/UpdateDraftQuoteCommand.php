<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Commands;

final readonly class UpdateDraftQuoteCommand
{
    public function __construct(
        public string $quoteId,
        public ?string $userId,
        /** @var array<string, mixed> */ public array $data,
    ) {}
}
