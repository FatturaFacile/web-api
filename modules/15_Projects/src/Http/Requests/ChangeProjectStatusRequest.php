<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Services\ProjectStatusService;

/**
 * Target status of a project: planned, active, on_hold, completed or cancelled (the PascalCase contract names are
 * accepted too).
 */
final class ChangeProjectStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $status = $this->input('status');

        if (is_string($status) && ($normalised = ProjectStatusService::normalise($status)) !== null) {
            $this->merge(['status' => $normalised]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(ProjectStatusService::Targets)],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
