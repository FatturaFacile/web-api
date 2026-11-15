<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs;

use Modules\HumanResources\Enums\SignatureStatus;

final readonly class SignatureRequestResultDTO
{
    public function __construct(
        public SignatureStatus $status,
        public ?string $externalId = null
    ) {}
}
