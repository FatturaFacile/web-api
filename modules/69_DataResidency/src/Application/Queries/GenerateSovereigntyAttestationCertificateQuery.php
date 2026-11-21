<?php

declare(strict_types=1);

namespace Modules\DataResidency\Application\Queries;

final readonly class GenerateSovereigntyAttestationCertificateQuery
{
    public function __construct(
        public string $tenantId,
        public ?string $legalEntityId,
        public string $actorId,
    ) {}
}
