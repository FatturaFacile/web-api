<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Commands;

final readonly class ReorderQuoteLinesCommand
{
    public function __construct(
        public string $quoteId,
        /** @var list<string> */ public array $sectionOrder,
        /** @var list<array{id: string, section_id: string|null}> */ public array $lineOrder,
    ) {}
}
