<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Customs\Enums\DeclarationType;

/**
 * Field types and sizes come from the e-dec schema v4.0.
 */
final class SaveDeclarationRequest extends FormRequest
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
        $isCreate = $this->isMethod('post');

        return [
            'declaration_type' => [$isCreate ? 'required' : 'prohibited', Rule::enum(DeclarationType::class)],
            'trader_declaration_number' => ['sometimes', 'string', 'between:1,22', 'regex:/^[^\/:"?*|\\\\]*$/'],
            'trader_reference' => ['sometimes', 'nullable', 'string', 'max:35'],
            'dossier_id' => ['sometimes', 'nullable', 'uuid', CustomsRules::exists('customs_dossiers')],
            'delivery_note_id' => ['sometimes', 'nullable', 'uuid', CustomsRules::exists('delivery_notes')],
            'consignor_contact_id' => ['sometimes', 'nullable', 'uuid', CustomsRules::exists('contacts')],
            'consignee_contact_id' => ['sometimes', 'nullable', 'uuid', CustomsRules::exists('contacts')],
            'importer_contact_id' => ['sometimes', 'nullable', 'uuid', CustomsRules::exists('contacts')],
            'clearance_location' => ['sometimes', 'nullable', 'integer', 'between:0,9'],
            'declaration_time' => ['sometimes', 'nullable', 'integer', 'between:0,99'],
            'declaration_kind' => ['sometimes', 'nullable', 'integer', 'between:0,99'],
            'language' => ['sometimes', 'string', 'in:de,fr,it,en'],
            'customs_office_number' => ['sometimes', 'nullable', 'string', 'max:8'],
            'dispatch_country' => ['sometimes', 'nullable', ...CustomsRules::country()],
            'destination_country' => ['sometimes', 'nullable', ...CustomsRules::country()],
            'transport_mode' => ['sometimes', 'nullable', 'integer', 'between:0,99'],
            'transportation_type' => ['sometimes', 'nullable', 'integer', 'between:0,99'],
            'transportation_country' => ['sometimes', 'nullable', ...CustomsRules::country()],
            'transportation_number' => ['sometimes', 'nullable', 'string', 'max:27'],
            'transport_in_container' => ['sometimes', 'boolean'],
            'container_numbers' => ['sometimes', 'nullable', 'array', 'max:99'],
            'container_numbers.*' => ['string', 'between:1,17'],
            'incoterms' => ['sometimes', 'nullable', 'string', 'size:3'],
            'invoice_currency_type' => ['sometimes', 'nullable', 'integer', 'between:0,99'],
            'declared_on' => ['sometimes', 'nullable', 'date'],

            'items' => ['sometimes', 'array', 'max:999'],
            'items.*.product_id' => ['nullable', 'uuid', CustomsRules::exists('products')],
            'items.*.trader_item_id' => ['nullable', 'string', 'between:1,16'],
            'items.*.description' => ['required', 'string', 'between:1,280'],
            'items.*.commodity_code' => ['required', 'string', 'between:1,10'],
            'items.*.statistical_code' => ['nullable', 'integer', 'between:0,999'],
            'items.*.gross_mass' => ['nullable', 'numeric', 'min:0', 'max:999999999.999'],
            'items.*.net_mass' => ['nullable', 'numeric', 'min:0', 'max:999999999.999'],
            'items.*.additional_unit' => ['nullable', 'numeric', 'min:0', 'max:999999999.9'],
            'items.*.customs_clearance_type' => ['nullable', 'integer', 'between:0,99'],
            'items.*.commercial_good' => ['nullable', 'integer', 'in:1,2'],
            'items.*.statistical_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'items.*.vat_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'items.*.vat_code' => ['nullable', 'integer', 'between:0,99'],
            'items.*.duty_rate' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'items.*.origin_country' => ['nullable', ...CustomsRules::country()],
            'items.*.preference' => ['nullable', 'boolean'],
            'items.*.packaging_type' => ['nullable', 'string', 'between:1,3'],
            'items.*.package_quantity' => ['nullable', 'integer', 'between:0,99999'],
            'items.*.packaging_reference' => ['nullable', 'string', 'max:42'],
            'items.*.produced_documents' => ['nullable', 'array', 'max:99'],
            'items.*.produced_documents.*.document_type' => ['required', 'string', 'between:1,4'],
            'items.*.produced_documents.*.reference' => ['nullable', 'string', 'max:35'],
            'items.*.produced_documents.*.issue_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function declarationData(): array
    {
        $data = $this->validated();

        foreach ($data['items'] ?? [] as $index => $item) {
            foreach (['gross_mass', 'net_mass', 'statistical_value', 'preference', 'package_quantity'] as $field) {
                if (! isset($item[$field])) {
                    unset($data['items'][$index][$field]);
                }
            }
        }

        return $data;
    }
}
