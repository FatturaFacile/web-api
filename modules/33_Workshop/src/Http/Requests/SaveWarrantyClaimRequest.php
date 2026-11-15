<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the warranty claim endpoints. Each route names the payload it validates: the claim itself, or the data of a
 * submit, decision or reimbursement.
 */
final class SaveWarrantyClaimRequest extends FormRequest
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
        $creating = str_ends_with((string) $this->route()?->getName(), 'warranty-claims.store');

        return match (str_replace('api.v1.workshop.', '', (string) $this->route()?->getName())) {
            'warranty-claims.store', 'warranty-claims.update' => [
                'folder_id' => [$creating ? 'required' : 'prohibited', 'uuid', $this->tenantOwns('workshop_folders')],
                'folder_line_id' => [$creating ? 'sometimes' : 'prohibited', 'nullable', 'uuid', $this->tenantOwns('workshop_folder_lines')],
                'product_id' => ['sometimes', 'nullable', 'uuid', $this->tenantExists('products')],
                'supplier_contact_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('contacts')],
                'description' => [$creating ? 'required' : 'sometimes', 'string', 'max:5000'],
                'claimed_amount' => ['sometimes', 'decimal:0,2', 'min:0'],
                'supplier_reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            ],
            'warranty-claims.submit' => [
                'supplier_reference' => ['nullable', 'string', 'max:100'],
            ],
            'warranty-claims.accept', 'warranty-claims.reject' => [
                'note' => ['nullable', 'string', 'max:500'],
            ],
            'warranty-claims.reimburse' => [
                'reimbursed_amount' => ['required', 'decimal:0,2', 'min:0'],
                'reimbursed_on' => ['required', 'date_format:Y-m-d'],
            ],
            default => [],
        };
    }
}
