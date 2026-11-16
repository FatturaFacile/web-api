<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

/**
 * The reason of a rejection (deal registration, MDF claim, quote discount).
 */
final class DecisionReasonRequest extends CrmExtensionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000']];
    }
}
