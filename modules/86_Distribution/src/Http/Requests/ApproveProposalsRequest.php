<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Distribution\Http\Requests\Concerns\ScopesToTenant;

/**
 * Proposals to turn into transfer orders.
 */
final class ApproveProposalsRequest extends FormRequest
{
    use ScopesToTenant;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['uuid', 'distinct', $this->tenantExists('distribution_replenishment_proposals')],
            'transfer_status' => ['required', 'string', Rule::in(['draft', 'approved'])],
        ];
    }
}
