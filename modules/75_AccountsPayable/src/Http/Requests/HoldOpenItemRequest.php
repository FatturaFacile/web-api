<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The reason to put an open item in dispute or under administrative block, or to release it (kept in its audit history).
 */
final class HoldOpenItemRequest extends FormRequest
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
        return ['reason' => ['required', 'string', 'min:3', 'max:1000']];
    }
}
