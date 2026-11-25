<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

use Modules\CorporateConventions\Domain\Enums\Relationship;

/**
 * Adds a first-degree family member up to the limit of the convention and issues a linked wallet pass.
 */
final readonly class RegisterConventionFamilyMemberCommand
{
    public function __construct(
        public string $enrollmentId,
        public string $fullName,
        public Relationship $relationship,
        public ?string $userId = null,
    ) {}
}
