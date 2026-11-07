<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceManagement\Enums\MessageVisibility;
use Modules\ServiceManagement\Enums\TicketStatus;
use Modules\ServiceManagement\Services\Helpdesk\TicketListFilter;

/**
 * Creates or changes a canned response, a macro or a saved view; the route name tells which.
 */
final class SaveAgentToolRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $route = (string) $this->route()?->getName();
        $common = ['is_shared' => ['sometimes', 'boolean']];

        if (str_contains($route, 'canned-responses')) {
            return [...$common,
                'title' => [$required, 'string', 'max:150'],
                'category' => ['sometimes', 'nullable', 'string', 'max:80'],
                'body' => [$required, 'string', 'max:20000'],
            ];
        }

        if (str_contains($route, 'macros')) {
            return [...$common,
                'name' => [$required, 'string', 'max:150'],
                'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'actions' => [$required, 'array'],
                'actions.set_status' => ['sometimes', 'string', Rule::in(TicketStatus::settableValues())],
                'actions.set_priority' => ['sometimes', 'string', 'in:low,medium,high,critical'],
                'actions.assign_team_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('esm_service_teams')],
                'actions.assign_technician_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('esm_service_technicians')],
                'actions.add_tags' => ['sometimes', 'array', 'max:20'],
                'actions.add_tags.*' => ['string', 'max:50'],
                'actions.remove_tags' => ['sometimes', 'array', 'max:20'],
                'actions.remove_tags.*' => ['string', 'max:50'],
                'actions.reply' => ['sometimes', 'array'],
                'actions.reply.body' => ['required_with:actions.reply', 'string', 'max:20000'],
                'actions.reply.visibility' => ['sometimes', 'string', Rule::enum(MessageVisibility::class)],
            ];
        }

        return [...$common,
            'name' => [$required, 'string', 'max:150'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'filters' => [$required, 'array'],
            'filters.*' => ['nullable'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function ($validator): void {
            foreach (array_keys((array) $this->input('filters', [])) as $key) {
                if (! in_array($key, TicketListFilter::Keys, true)) {
                    $validator->errors()->add("filters.{$key}", 'Unknown filter.');
                }
            }
        }];
    }
}
