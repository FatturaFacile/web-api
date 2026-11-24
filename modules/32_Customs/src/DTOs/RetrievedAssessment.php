<?php

declare(strict_types=1);

namespace Modules\Customs\DTOs;

/**
 * A downloaded assessment decision: the decision itself, the message that carried its signature when there is one,
 * and the PDF when the source gives it.
 */
final readonly class RetrievedAssessment
{
    public function __construct(
        public string $xml,
        public ?string $signedEnvelope = null,
        public ?string $pdf = null,
    ) {}
}
