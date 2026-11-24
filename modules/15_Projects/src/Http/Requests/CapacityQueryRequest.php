<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Projects\Services\ResourceCapacityService;

/**
 * Window of the capacity report: at most ResourceCapacityService::MaxWeeks weeks, optionally for one person.
 */
final class CapacityQueryRequest extends FormRequest
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
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'user_id' => ['nullable', 'uuid', $this->tenantMember()],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $days = CarbonImmutable::parse((string) $this->input('from'))->diffInDays(CarbonImmutable::parse((string) $this->input('to')));

                if ($days > ResourceCapacityService::MaxWeeks * 7) {
                    $validator->errors()->add('to', 'The window can span at most '.ResourceCapacityService::MaxWeeks.' weeks.');
                }
            },
        ];
    }
}
