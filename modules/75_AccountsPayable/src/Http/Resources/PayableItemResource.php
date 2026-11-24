<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AccountsPayable\Models\ApOpenItem;
use Modules\AccountsPayable\Services\AccountingPayablesPresenter;

/**
 * An open item in the shape of the web client (`/api/v1/accounting/payables/items`).
 *
 * @mixin ApOpenItem
 */
final class PayableItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return app(AccountingPayablesPresenter::class)->item($this->resource, (bool) $request->routeIs('accounts-payable.payables.items.show', 'accounts-payable.payables.items.block', 'accounts-payable.payables.items.release'));
    }
}
