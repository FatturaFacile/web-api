<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Distribution\Http\Requests\Concerns\ScopesToTenant;

/**
 * New quantity of a pending proposal.
 */
final class AdjustProposalRequest extends FormRequest
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
            'suggested_quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
