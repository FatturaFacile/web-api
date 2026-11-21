<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Commands;

final readonly class RejectQuoteCommand
{
    public function __construct(
        public string $token,
        public ?string $session,
        public string $reason,
        public ?string $ip = null,
    ) {}
}
