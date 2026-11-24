<?php

declare(strict_types=1);

namespace Modules\Customs\DTOs;

/**
 * What the customs channel answered to a declaration.
 */
final readonly class SubmissionResult
{
    public function __construct(
        public bool $isAccepted,
        public string $response,
        public ?string $mrn = null,
        public ?int $version = null,
        public ?string $rejectionReason = null,
    ) {}
}
