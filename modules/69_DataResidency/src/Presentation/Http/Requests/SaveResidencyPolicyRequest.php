<?php

declare(strict_types=1);

namespace Modules\DataResidency\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\DataResidency\Domain\Enums\JurisdictionZone;

final class SaveResidencyPolicyRequest extends FormRequest
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
            'legal_entity_id' => ['nullable', 'uuid'],
            'jurisdiction_zone' => ['required', Rule::enum(JurisdictionZone::class)],
            'primary_db_node_id' => ['required', 'uuid'],
            'replica_db_node_ids' => ['nullable', 'array', 'max:8'],
            'replica_db_node_ids.*' => ['uuid', 'distinct'],
            's3_storage_node_id' => ['required', 'uuid'],
            'vector_db_node_id' => ['required', 'uuid'],
            'ai_inference_node_id' => ['required', 'uuid'],
            'backup_node_id' => ['required', 'uuid'],
            'queue_node_id' => ['required', 'uuid'],
            'enforce_strict_egress_block' => ['nullable', 'boolean'],
            'allow_aggregated_non_pii_consolidation' => ['nullable', 'boolean'],
            'second_approver_id' => ['nullable', 'uuid'],
        ];
    }
}
