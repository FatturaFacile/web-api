<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

/**
 * A claim against an MDF fund.
 */
final class SubmitMdfClaimRequest extends CrmExtensionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'activity' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'activity_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
