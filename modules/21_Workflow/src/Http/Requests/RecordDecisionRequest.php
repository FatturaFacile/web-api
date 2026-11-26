<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Workflow\Enums\DecisionType;

/**
 * A decision on a pending step: approve, reject (reason mandatory), request changes (reason mandatory) or reassign to
 * another member. Both the present form (`approve`) and the stored form (`approved`) are accepted.
 */
final class RecordDecisionRequest extends FormRequest
{
    /**
     * Whether the user may decide is checked against the step by the policy in the controller.
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
        $needsReason = in_array(DecisionType::fromInput((string) $this->input('decision')), [DecisionType::Reject, DecisionType::RequestChanges], true);

        return [
            'decision' => ['required', 'string', Rule::in(['approve', 'approved', 'reject', 'rejected', 'abstained', 'request_changes', 'reassign'])],
            'comment' => [$needsReason ? 'required' : 'nullable', 'string', 'max:2000'],
            'reassign_to_user_id' => ['required_if:decision,reassign', 'nullable', 'uuid', TenantRule::member()],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'signature_payload' => ['nullable', 'array'],
        ];
    }
}
