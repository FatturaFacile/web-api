<?php

declare(strict_types=1);

namespace Modules\Intrastat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Intrastat\Support\IntrastatCodes;

/**
 * A detail row. The request checks the shape and the closed code lists; which columns a section needs is checked by
 * the validation of the whole list, so that a draft can be saved incomplete.
 */
final class DeclarationLineRequest extends FormRequest
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
        $country = ['nullable', 'string', 'regex:/^[A-Z]{2}$/'];

        return [
            'section' => [$this->isMethod('post') ? 'required' : 'prohibited', 'integer', 'between:1,4'],
            'partner_country_code' => $country,
            'partner_vat_number' => ['nullable', 'string', 'regex:/^[A-Z0-9]{1,12}$/'],
            'amount_eur' => ['nullable', 'integer', 'min:0', 'max:9999999999999'],
            'amount_currency' => ['nullable', 'integer', 'min:0', 'max:9999999999999'],
            'transaction_nature_a' => ['nullable', 'string', Rule::in([...array_keys(IntrastatCodes::TransactionNatures), ...array_keys(IntrastatCodes::TriangularNatureLetters)])],
            'transaction_nature_b' => ['nullable', 'string', 'regex:/^\d$/'],
            'cn8_code' => ['nullable', 'string', 'regex:/^\d{8}$/'],
            'net_mass_kg' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
            'supplementary_units' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
            'statistical_value_eur' => ['nullable', 'integer', 'min:0', 'max:9999999999999'],
            'delivery_terms' => ['nullable', 'string', Rule::in(array_keys(IntrastatCodes::DeliveryTermGroups))],
            'transport_mode' => ['nullable', 'string', Rule::in(array_map('strval', array_keys(IntrastatCodes::TransportModes)))],
            'goods_country_code' => $country,
            'origin_country_code' => $country,
            'province_code' => $country,
            'invoice_number' => ['nullable', 'string', 'max:15', 'regex:/^[\x20-\x7E]+$/'],
            'invoice_date' => ['nullable', 'date'],
            'service_code' => ['nullable', 'string', 'regex:/^\d{6}$/'],
            'supply_mode' => ['nullable', 'string', Rule::in(array_keys(IntrastatCodes::SupplyModes))],
            'payment_method' => ['nullable', 'string', Rule::in(array_keys(IntrastatCodes::PaymentMethods))],
            'payment_country_code' => $country,
            'corrected_month' => ['nullable', 'integer', 'between:0,12'],
            'corrected_quarter' => ['nullable', 'integer', 'between:0,4'],
            'corrected_year' => ['nullable', 'integer', 'between:2000,2099'],
            'sign' => ['nullable', 'string', Rule::in(['+', '-'])],
            'corrected_customs_section' => ['nullable', 'string', 'regex:/^\d{1,6}$/'],
            'corrected_protocol_year' => ['nullable', 'integer', 'between:2000,2099'],
            'corrected_protocol_number' => ['nullable', 'string', 'regex:/^\d{1,6}$/'],
            'corrected_line_number' => ['nullable', 'integer', 'between:1,99999'],
            'is_cancellation' => ['sometimes', 'boolean'],
        ];
    }
}
