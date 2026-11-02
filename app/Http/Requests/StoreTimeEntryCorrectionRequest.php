<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TimeEntry;
use App\Models\TimeEntryCorrection;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreTimeEntryCorrectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $entry = TimeEntry::findOrFail($this->route('entry'));
        abort_unless($entry instanceof TimeEntry && $entry->tenant_id === app(TenantContext::class)->getTenantId(), 404);

        return Gate::allows('create', [TimeEntryCorrection::class, $entry]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'clock_in' => ['required', 'date', 'before_or_equal:now'],
            'clock_out' => ['required', 'date', 'after:clock_in', 'before_or_equal:now'],
            'break_minutes' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'status' => ['missing'],
            'requested_by' => ['missing'],
            'reviewed_by' => ['missing'],
        ];
    }
}
