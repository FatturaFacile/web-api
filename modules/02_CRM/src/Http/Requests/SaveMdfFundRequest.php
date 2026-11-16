<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

/**
 * The MDF fund of a partner for a year; without an amount the fund accrues from the partner tier.
 */
final class SaveMdfFundRequest extends CrmExtensionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'partner_id' => ['required', 'uuid', $this->ownRow('crm_partners')],
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'allocated_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ];
    }
}
