<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\BusinessIntelligence\Models\BiShare;

/**
 * Sharing of a dashboard or a saved query with a member of the company.
 */
final class ShareRequest extends BiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'resource_type' => ['required', Rule::in(BiShare::ResourceTypes)],
            'resource_id' => ['required', 'uuid'],
            'user_id' => ['required', 'uuid'],
            'permission' => ['required', Rule::in(BiShare::Permissions)],
        ];
    }
}
