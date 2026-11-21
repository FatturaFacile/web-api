<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A mutating endpoint that takes everything from the path: publish, lock and claim a shift.
 */
final class ShiftActionRequest extends FormRequest
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
        return [];
    }
}
