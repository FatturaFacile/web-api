<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Commands;

final readonly class InitiateQuoteSignatureCommand
{
    public function __construct(
        public string $token,
        public ?string $session,
        public string $signerName,
        public ?string $signerRole,
        public ?string $signerEmail,
        public ?string $signerPhone,
        public string $channel,
        public bool $consent,
        public ?string $ip,
        public ?string $userAgent,
    ) {}
}
