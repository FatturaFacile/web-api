<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RefreshCubesRequest extends FormRequest
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
        return [];
    }
}
