<?php

declare(strict_types=1);

namespace Modules\DownPayments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\DownPayments\Domain\Enums\ChainStatus;
use Modules\DownPayments\Domain\Enums\SourceType;

/**
 * Filters of the list of down-payment chains.
 */
final class ChainIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is enforced by the policy in the controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string', Rule::enum(ChainStatus::class)],
            'source_type' => ['nullable', Rule::enum(SourceType::class)],
            'source_id' => ['nullable', 'uuid'],
            'country' => ['nullable', 'string', 'in:IT,CH'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
