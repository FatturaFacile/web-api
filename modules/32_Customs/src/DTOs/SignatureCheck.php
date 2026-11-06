<?php

declare(strict_types=1);

namespace Modules\Customs\DTOs;

use Modules\Customs\Enums\SignatureStatus;

/**
 * The outcome of a signature check, kept with the decision as its verification record.
 */
final readonly class SignatureCheck
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(
        public SignatureStatus $status,
        public array $report,
    ) {}
}
