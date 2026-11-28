<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\WithholdingRecipients\Models\RecipientSettlement;
use Modules\WithholdingRecipients\Services\SettlementService;

final class ListTaxRegisterHandler
{
    public function __construct(private readonly SettlementService $settlements) {}

    /**
     * @return LengthAwarePaginator<int, RecipientSettlement>
     */
    public function handle(ListTaxRegisterQuery $query): LengthAwarePaginator
    {
        return $this->settlements->register($query->filters)->paginate($query->perPage);
    }
}
