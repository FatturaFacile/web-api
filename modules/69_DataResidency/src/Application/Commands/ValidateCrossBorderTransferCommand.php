<?php

declare(strict_types=1);

namespace Modules\DataResidency\Application\Commands;

final readonly class ValidateCrossBorderTransferCommand
{
    public function __construct(
        public string $tenantId,
        public ?string $legalEntityId,
        public string $purpose,
        public string $target,
        public ?string $destinationCountryIso2 = null,
    ) {}
}
