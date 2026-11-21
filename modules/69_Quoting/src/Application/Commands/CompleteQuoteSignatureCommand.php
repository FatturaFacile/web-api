<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Commands;

final readonly class CompleteQuoteSignatureCommand
{
    public function __construct(
        public string $token,
        public ?string $session,
        public string $envelopeId,
        /** @var array<string, mixed> */ public array $proof,
        public ?string $ip,
        public ?string $userAgent,
    ) {}
}
