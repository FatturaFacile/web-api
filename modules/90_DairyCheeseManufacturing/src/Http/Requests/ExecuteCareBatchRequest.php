<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class ExecuteCareBatchRequest extends DairyFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'care_type' => ['required', 'in:brining,turning,brushing,rind_washing,piercing,expert_grading'],
            'scope' => ['required', 'in:shelf,rack,batch'],
            'target_id' => ['required', 'uuid'],
            'performed_by' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
