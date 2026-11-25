<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Commands;

/**
 * Command to create a new Chart of Accounts node.
 */
final readonly class CreateAccountCommand
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $tenantId,
        public array $attributes,
    ) {}
}
