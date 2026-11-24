<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Commands;

final readonly class VerifyPublicQuoteAccessCommand
{
    public function __construct(
        public string $token,
        public string $pin,
        public ?string $ip = null,
    ) {}
}
