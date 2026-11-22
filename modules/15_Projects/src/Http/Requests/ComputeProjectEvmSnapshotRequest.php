<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Recomputation of the financial snapshot of a project, optionally as of a past date.
 */
final class ComputeProjectEvmSnapshotRequest extends FormRequest
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
        return [
            'as_of' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
