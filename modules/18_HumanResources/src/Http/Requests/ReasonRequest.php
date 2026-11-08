<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An action that needs a reason kept in the history (cancelling a leave request, reopening a closed month).
 */
final class ReasonRequest extends FormRequest
{
    /**
     * Access is checked by the route middleware and the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:1000']];
    }
}
