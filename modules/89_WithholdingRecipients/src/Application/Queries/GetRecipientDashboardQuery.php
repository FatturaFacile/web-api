<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Application\Queries;

final readonly class GetRecipientDashboardQuery
{
    /**
     * @param  array<string, mixed>  $filters  regime, jurisdiction, cu_causal, search, year, only_alerts
     */
    public function __construct(public array $filters = []) {}
}
