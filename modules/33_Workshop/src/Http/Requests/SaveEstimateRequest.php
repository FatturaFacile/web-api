<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Workshop\Models\WorkshopFolder;
use Modules\Workshop\Models\WorkshopFolderLine;

/**
 * Validates the estimate endpoints. Each route names the payload it validates: the estimate itself, a line, or the
 * decision note of a send, approve or reject.
 */
final class SaveEstimateRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Always allowed here: who may call the endpoint is decided by the module.access middleware and the policy check
     * of the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST') && str_ends_with((string) $this->route()?->getName(), 'estimates.store');
        $presence = $creating ? 'required' : 'sometimes';

        return match (str_replace('api.v1.workshop.', '', (string) $this->route()?->getName())) {
            'estimates.store', 'estimates.update' => [
                'vehicle_id' => [$creating ? 'required' : 'prohibited', 'uuid', $this->tenantOwns('vehicles')],
                'contact_id' => ['sometimes', 'uuid', $this->tenantOwns('contacts')],
                'kind' => [$presence, Rule::in(WorkshopFolder::Kinds)],
                'currency' => [$presence, 'string', 'size:3'],
                'valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'mileage_in' => ['sometimes', 'nullable', 'integer', 'min:0'],
                'complaint' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'diagnosis' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'lines' => [$creating ? 'sometimes' : 'prohibited', 'array', 'max:200'],
                ...($creating ? $this->lineRules('lines.*.') : []),
            ],
            'estimates.lines.store' => $this->lineRules(),
            'estimates.approve', 'estimates.reject' => [
                'note' => ['nullable', 'string', 'max:500'],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function lineRules(string $prefix = ''): array
    {
        return [
            $prefix.'line_type' => ['required', Rule::in(WorkshopFolderLine::Types)],
            $prefix.'product_id' => ['nullable', 'uuid', $this->tenantExists('products')],
            $prefix.'description' => ['required', 'string', 'max:500'],
            $prefix.'quantity' => ['required', 'decimal:0,4', 'gt:0'],
            $prefix.'unit_price' => ['required', 'decimal:0,4', 'min:0'],
            $prefix.'discount_percentage' => ['nullable', 'decimal:0,2', 'min:0', 'max:100'],
            $prefix.'tax_rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
        ];
    }
}
