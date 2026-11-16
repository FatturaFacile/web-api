<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

use Closure;

/**
 * Assembles the text of a contract from a template.
 */
final class StoreVersionFromTemplateRequest extends AuthoringFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'template_id' => [$this->routeIs('*.templates.preview') ? 'sometimes' : 'required', 'uuid', $this->ownRow('contract_templates')],
            'variables' => ['nullable', 'array'],
            'variables.*' => ['nullable', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_scalar($value)) {
                    $fail('The '.$attribute.' must be a scalar value.');
                }
            }],
            'include_optional' => ['sometimes', 'array'],
            'include_optional.*' => ['uuid'],
            'fallback_ranks' => ['sometimes', 'array'],
            'fallback_ranks.*' => ['integer', 'min:1', 'max:50'],
        ];
    }
}
