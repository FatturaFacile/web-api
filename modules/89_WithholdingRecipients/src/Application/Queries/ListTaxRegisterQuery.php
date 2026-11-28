<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Application\Queries;

final readonly class ListTaxRegisterQuery
{
    /**
     * @param  array<string, mixed>  $filters  recipient_profile_id, regime, jurisdiction, tax_year, accrual_month
     */
    public function __construct(
        public array $filters = [],
        public int $perPage = 25,
    ) {}
}
