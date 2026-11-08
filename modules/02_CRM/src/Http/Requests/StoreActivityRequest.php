<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Logging of a CRM activity on a lead, account, contact, opportunity or campaign. The related record and the assignee
 * must belong to the active tenant, so an activity can never be attached to another tenant's data.
 */
final class StoreActivityRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Table of each record type an activity can be related to.
     */
    public const array RelatedTables = [
        'lead' => 'crm_leads',
        'account' => 'crm_accounts',
        'contact' => 'contacts',
        'opportunity' => 'crm_opportunities',
        'campaign' => 'crm_campaigns',
    ];

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
            'subject' => ['required', 'string', 'max:200'],
            'type' => ['required', 'string', 'in:task,call,meeting,note,email,follow_up'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,urgent'],
            'due_date' => ['nullable', 'date'],
            'reminder_at' => ['nullable', 'date'],
            'assigned_user_id' => ['nullable', 'uuid', $this->tenantMember()],
            'related_type' => ['nullable', 'required_with:related_id', 'string', 'in:lead,account,contact,opportunity,campaign'],
            'related_id' => ['nullable', 'required_with:related_type', 'uuid'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $type = $this->input('related_type');
                $id = $this->input('related_id');

                if ($validator->errors()->isNotEmpty() || ! is_string($type) || ! is_string($id) || ! isset(self::RelatedTables[$type])) {
                    return;
                }

                $exists = validator(['related_id' => $id], ['related_id' => [$this->tenantOwns(self::RelatedTables[$type])]])->passes();

                if (! $exists) {
                    $validator->errors()->add('related_id', __('validation.exists', ['attribute' => 'related_id']));
                }
            },
        ];
    }
}
