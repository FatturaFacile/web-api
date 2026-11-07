<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Contracts\Enums\ContractKind;
use Modules\ServiceManagement\Models\ServiceContract;

/**
 * Accepts a row of the register or either contract model, and answers with the same shape.
 */
final class ContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $isModel = $row instanceof Model;
        $isCustomer = $isModel ? $row instanceof ServiceContract : $row->kind === ContractKind::Customer->value;

        $read = static fn (string $customerField, string $supplierField, string $rowField): mixed => $isModel
            ? $row->getAttribute($isCustomer ? $customerField : $supplierField)
            : $row->{$rowField};

        return [
            'kind' => $isCustomer ? ContractKind::Customer->value : ContractKind::Supplier->value,
            'id' => (string) $row->id,
            'contract_number' => $row->contract_number,
            'title' => $row->title,
            'counterparty_id' => $read('customer_id', 'supplier_id', 'counterparty_id'),
            'start_date' => $this->dateOf($read('start_date', 'starts_at', 'start_date')),
            'end_date' => $this->dateOf($read('end_date', 'ends_at', 'end_date')),
            'contract_value' => $read('annual_value', 'total_committed_amount', 'contract_value'),
            'currency' => $isModel ? ($isCustomer ? $row->currency : null) : $row->currency,
            'status' => $row->status,
            'auto_renew' => (bool) $row->auto_renew,
            'notice_period_days' => $row->notice_period_days === null ? null : (int) $row->notice_period_days,
            'renewal_term_months' => $row->renewal_term_months === null ? null : (int) $row->renewal_term_months,
            'alert_days_before' => (int) $row->alert_days_before,
            'notice_deadline' => $this->dateOf($row->notice_deadline),
            'notice_given_at' => $row->notice_given_at,
        ];
    }

    private function dateOf(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toDateString();
    }
}
