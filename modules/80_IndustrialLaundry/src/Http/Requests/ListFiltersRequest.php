<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\IndustrialLaundry\Domain\Enums\ArticleType;
use Modules\IndustrialLaundry\Domain\Enums\CertificationStatus;
use Modules\IndustrialLaundry\Domain\Enums\CustomerSegment;
use Modules\IndustrialLaundry\Domain\Enums\LifecycleState;
use Modules\IndustrialLaundry\Domain\Enums\ScanDirection;

/**
 * Filters shared by the list and monitor endpoints of the module.
 */
final class ListFiltersRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', Rule::in(LifecycleState::values())],
            'article_type' => ['nullable', Rule::in(ArticleType::values())],
            'customer_id' => ['nullable', 'uuid'],
            'contact_id' => ['nullable', 'uuid'],
            'segment' => ['nullable', Rule::in(CustomerSegment::values())],
            'direction' => ['nullable', Rule::in(ScanDirection::values())],
            'status' => ['nullable', 'string', Rule::in([...CertificationStatus::values(), 'drafted', 'invoiced'])],
            'active' => ['nullable', 'boolean'],
            'days' => ['nullable', 'integer', 'between:1,3650'],
            'as_of' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'barcode' => ['nullable', 'string', 'max:60'],
            'limit_reached_only' => ['nullable', 'boolean'],
            'with_preview' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }
}
