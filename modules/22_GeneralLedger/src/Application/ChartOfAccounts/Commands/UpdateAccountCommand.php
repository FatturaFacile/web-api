<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Commands;

use Modules\GeneralLedger\Models\ChartOfAccount;

final readonly class UpdateAccountCommand
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public ChartOfAccount $account,
        public array $attributes,
    ) {}
}
