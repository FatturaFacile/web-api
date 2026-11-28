<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\AdventureParks\Domain\Enums\PpeCategory;
use Modules\AdventureParks\Domain\Enums\PpeSize;
use Modules\AdventureParks\Domain\Enums\PpeStatus;
use Modules\AdventureParks\Models\PpeAsset;

final class PpeIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', PpeAsset::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['nullable', Rule::enum(PpeCategory::class)],
            'size' => ['nullable', Rule::enum(PpeSize::class)],
            'status' => ['nullable', Rule::enum(PpeStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }
}
