<?php

declare(strict_types=1);

namespace Modules\DownPayments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\DownPayments\Http\Requests\Concerns\PlanRules;

/**
 * The source and the installment plan the wizard previews.
 */
final class PreviewPlanRequest extends FormRequest
{
    use PlanRules;

    public function authorize(): bool
    {
        return true; // Authorization is enforced by the policy in the controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->planRules();
    }
}
