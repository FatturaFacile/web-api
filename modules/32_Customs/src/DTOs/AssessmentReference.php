<?php

declare(strict_types=1);

namespace Modules\Customs\DTOs;

use Modules\Customs\Enums\AssessmentDocumentType;

/**
 * One line of the list of assessment decisions available on a channel.
 */
final readonly class AssessmentReference
{
    public function __construct(
        public string $mrn,
        public int $version,
        public AssessmentDocumentType $documentType,
        public string $documentDate,
    ) {}
}
