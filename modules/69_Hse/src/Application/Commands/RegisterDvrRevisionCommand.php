<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Commands;

use Carbon\CarbonImmutable;

final readonly class RegisterDvrRevisionCommand
{
    /**
     * @param  array<string, array{signed_by: string, signed_at: string}>  $signatures  employer, rspp, rls, medico_competente
     */
    public function __construct(
        public string $tenantId,
        public CarbonImmutable $issuedAt,
        public ?CarbonImmutable $reviewDueAt,
        public array $signatures,
        public ?string $documentId,
    ) {}
}
