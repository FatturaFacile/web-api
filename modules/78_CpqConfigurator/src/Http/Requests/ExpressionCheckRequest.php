<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Http\Requests;

/**
 * An expression to check against the characteristics of a model while it is being written.
 */
final class ExpressionCheckRequest extends CpqFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expression' => ['required', 'string', 'max:2000'],
        ];
    }
}
