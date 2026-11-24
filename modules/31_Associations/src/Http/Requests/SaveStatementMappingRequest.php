<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Support\TenantRules;
use Modules\Associations\Support\ThirdSectorScheme;

final class SaveStatementMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $scheme = (string) $this->input('scheme');

        return [
            'scheme' => ['required', Rule::in(['A', 'B', 'D'])],
            'line_code' => ['required', 'string', static function (string $attribute, mixed $value, Closure $fail) use ($scheme): void {
                if (! is_string($value) || ! ThirdSectorScheme::isLine($scheme, $value)) {
                    $fail(__('association_finance.line_unknown'));
                }
            }],
            'account_id' => ['required', 'uuid', TenantRules::exists('chart_of_accounts')],
            'cost_center_id' => ['sometimes', 'nullable', 'uuid', 'prohibited_if:scheme,A', TenantRules::exists('cost_centers')],
            'is_commercial' => ['sometimes', 'boolean'],
        ];
    }
}
