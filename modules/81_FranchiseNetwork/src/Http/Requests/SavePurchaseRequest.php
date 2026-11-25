<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\FranchiseNetwork\Http\Requests\Concerns\ScopesToTenant;

final class SavePurchaseRequest extends FormRequest
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
            'purchased_amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
