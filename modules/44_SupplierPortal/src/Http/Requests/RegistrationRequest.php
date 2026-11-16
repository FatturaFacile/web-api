<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Modules\SupplierPortal\Services\TaxIdentifierValidator;

/**
 * Registration data a supplier sends from the portal. The VAT number (Italian partita IVA) or UID (Swiss CHE number)
 * must be well formed for the country.
 */
final class RegistrationRequest extends FormRequest
{
    /**
     * The portal token authenticates the supplier.
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
        return [
            'legal_name' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'size:2', 'in:IT,CH'],
            'vat_number' => ['required', 'string', 'max:50'],
            'tax_code' => ['nullable', 'string', 'max:50'],
            'address_line' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:120'],
            'contact_name' => ['required', 'string', 'max:150'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! app(TaxIdentifierValidator::class)->isValid((string) $this->input('country'), (string) $this->input('vat_number'))) {
                $validator->errors()->add('vat_number', __('supplier_portal.validation.vat_number_invalid'));
            }
        });
    }
}
