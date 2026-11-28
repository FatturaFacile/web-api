<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class ScheduleCareRequest extends DairyFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'care_type' => ['required', 'in:brining,turning,brushing,rind_washing,piercing,expert_grading'],
            'scope' => ['required', 'in:wheel,shelf,rack,batch'],
            'target_id' => ['required', 'uuid'],
            'scheduled_for' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
