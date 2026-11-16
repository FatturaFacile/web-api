<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Requests;

/**
 * Payload of the complaint transitions: investigate (owner_id), root cause, link, reply, reject and close. Only the
 * fields of the route are validated.
 */
final class ComplaintActionRequest extends QualityFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()?->getActionMethod()) {
            'investigate' => ['owner_id' => ['nullable', 'uuid', $this->member()]],
            'rootCause' => ['root_cause' => ['required', 'string', 'max:4000']],
            'link' => [
                'nonconformity_id' => ['nullable', 'required_without:capa_action_id', 'uuid', $this->ownRow('qms_nonconformities')],
                'capa_action_id' => ['nullable', 'required_without:nonconformity_id', 'uuid', $this->ownRow('qms_capa_actions')],
            ],
            'reply' => ['reply_text' => ['required', 'string', 'max:8000']],
            'reject' => ['reason' => ['required', 'string', 'max:2000']],
            'close' => ['closure_notes' => ['nullable', 'string', 'max:4000']],
            default => [],
        };
    }
}
