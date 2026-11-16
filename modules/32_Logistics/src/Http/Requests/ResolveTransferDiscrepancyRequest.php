<?php

declare(strict_types=1);

namespace Modules\Logistics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Logistics\Enums\DiscrepancyStatus;

/** Authorization is enforced per route by the logistics.can middleware (RBAC matrix). */
final class ResolveTransferDiscrepancyRequest extends FormRequest
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
            'resolution' => ['required', Rule::in(DiscrepancyStatus::resolutions())],
        ];
    }
}
