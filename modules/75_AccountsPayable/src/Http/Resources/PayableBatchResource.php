<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AccountsPayable\Models\ApPaymentRun;
use Modules\AccountsPayable\Services\AccountingPayablesPresenter;

/**
 * A payment run in the shape of the web client's batch (`/api/v1/accounting/payables/batches`).
 *
 * @mixin ApPaymentRun
 */
final class PayableBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return app(AccountingPayablesPresenter::class)->batch($this->resource);
    }
}
