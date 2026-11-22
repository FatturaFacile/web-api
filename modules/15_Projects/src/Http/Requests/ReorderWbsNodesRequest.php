<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Several WBS moves at once: each item names the node, its new parent (null = top level) and its new position.
 */
final class ReorderWbsNodesRequest extends FormRequest
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
        return [
            'nodes' => ['required', 'array', 'min:1', 'max:1000'],
            'nodes.*.id' => ['required', 'uuid', 'distinct', $this->tenantOwns('project_wbs_nodes')],
            'nodes.*.parent_node_id' => ['nullable', 'uuid', $this->tenantOwns('project_wbs_nodes')],
            'nodes.*.sort_order' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
