<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Commands;

final readonly class CreateQuoteCommand
{
    public function __construct(
        public string $tenantId,
        public ?string $userId,
        /** @var array<string, mixed> */ public array $data,
    ) {}
}
