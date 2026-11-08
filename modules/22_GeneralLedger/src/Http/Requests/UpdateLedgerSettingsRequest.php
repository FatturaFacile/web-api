<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Modules\GeneralLedger\Enums\PostingAccountRole;

/**
 * Default posting accounts by role and four-eyes policy of manual journals. Only the keys sent are changed.
 */
final class UpdateLedgerSettingsRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'posting_accounts' => ['sometimes', 'array:'.implode(',', PostingAccountRole::values())],
            'manual_journal_approval_required' => ['sometimes', 'boolean'],
            'manual_journal_approval_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999999'],
        ];

        foreach (PostingAccountRole::values() as $role) {
            $rules["posting_accounts.{$role}"] = ['sometimes', 'nullable', 'uuid', $this->tenantOwns('chart_of_accounts')];
        }

        return $rules;
    }
}
