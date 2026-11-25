<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

/**
 * Generates the cash flow statement: OIC 10 (Italy) or FER 4 (Switzerland), indirect or direct method.
 */
final readonly class GenerateCashFlowStatementCommand
{
    public function __construct(public string $dossierId, public ?string $method = null, public ?string $userId = null) {}
}
