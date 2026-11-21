<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An upload of the local audit queue of a desktop or mobile device (shared contract of both clients).
 */
final class StoreOfflineAuditBatchRequest extends FormRequest
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
            'device_id' => ['required', 'string', 'max:128'],
            'batch_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'origin' => ['required', 'string', 'max:40'],
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.sequence_number' => ['required', 'integer', 'min:1'],
            'entries.*.previous_hash' => ['required', 'string', 'size:64', 'regex:/^[0-9a-fA-F]{64}$/'],
            'entries.*.payload_hash' => ['required', 'string', 'size:64', 'regex:/^[0-9a-fA-F]{64}$/'],
            'entries.*.record_hash' => ['required', 'string', 'size:64', 'regex:/^[0-9a-fA-F]{64}$/'],
            'entries.*.occurred_at' => ['required', 'string', 'max:40'],
            'entries.*.entity_type' => ['required', 'string', 'max:191'],
            'entries.*.entity_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'entries.*.action' => ['required', 'string', 'max:80'],
            'entries.*.payload' => ['sometimes', 'nullable'],
            'entries.*.payload_json' => ['sometimes', 'nullable', 'string'],
            'entries.*.metadata' => ['sometimes', 'nullable'],
            'entries.*.metadata_json' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
