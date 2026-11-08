<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use Modules\Manufacturing\Models\MfgAccountingSetting;

/**
 * Validation of the ledger account codes of manufacturing (null goes back to the module default).
 */
final class UpdateAccountingSettingsRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [];
        foreach (MfgAccountingSetting::AccountRoles as $role) {
            $rules[$role.'_account_code'] = ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9.\-_]+$/'];
        }

        return $rules;
    }
}
