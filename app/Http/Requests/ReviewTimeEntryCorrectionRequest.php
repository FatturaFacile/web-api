<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TimeEntryCorrection;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ReviewTimeEntryCorrectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $correction = TimeEntryCorrection::findOrFail($this->route('correction'));
        abort_unless($correction instanceof TimeEntryCorrection && $correction->tenant_id === app(TenantContext::class)->getTenantId(), 404);

        return Gate::allows('review', $correction);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => [$this->routeIs('time-attendance.corrections.reject') ? 'required' : 'nullable', 'string', 'min:5', 'max:1000'],
            'clock_in' => ['missing'],
            'clock_out' => ['missing'],
            'break_minutes' => ['missing'],
            'status' => ['missing'],
            'reviewed_by' => ['missing'],
        ];
    }
}
