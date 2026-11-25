<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\TenderManagement\Domain\Enums\Jurisdiction;
use Modules\TenderManagement\Domain\Enums\ParticipationStatus;
use Modules\TenderManagement\Domain\Enums\VaultDocType;
use Modules\TenderManagement\Domain\Enums\VerificationStatus;

/**
 * The query-string filters of every list of the module.
 */
final class ListFiltersRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:30'],
            'jurisdiction' => ['nullable', 'string', Rule::in([...Jurisdiction::values(), 'IT', 'CH'])],
            'participation_status' => ['nullable', Rule::in(array_map(static fn (ParticipationStatus $status): string => $status->value, ParticipationStatus::cases()))],
            'doc_type' => ['nullable', Rule::in(VaultDocType::values())],
            'verification_status' => ['nullable', Rule::in(VerificationStatus::values())],
            'contracting_authority_name' => ['nullable', 'string', 'max:200'],
            'cpv_code' => ['nullable', 'string', 'max:12'],
            'winners_only' => ['nullable', 'boolean'],
            'acknowledged' => ['nullable', 'boolean'],
            'deadline_within_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
