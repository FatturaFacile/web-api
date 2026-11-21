<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Commands;

final readonly class UpdateCustomerQuoteSelectionsCommand
{
    public function __construct(
        public string $token,
        public ?string $session,
        /** @var array<string, bool> */ public array $selections,
        public ?string $ip = null,
    ) {}
}
