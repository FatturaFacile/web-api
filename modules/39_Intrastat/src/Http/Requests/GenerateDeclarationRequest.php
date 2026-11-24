<?php

declare(strict_types=1);

namespace Modules\Intrastat\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Intrastat\Enums\SupplyKind;
use Modules\Intrastat\Support\IntrastatCodes;

/**
 * Drafts a list from the invoices of a period. The defaults fill the columns the invoices do not hold; how to treat the
 * invoice lines that cannot be classified as goods or services is the caller's choice, otherwise they are reported.
 */
final class GenerateDeclarationRequest extends DeclarationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'unclassified_as' => ['nullable', Rule::enum(SupplyKind::class)],
            'defaults' => ['sometimes', 'array'],
            'defaults.transaction_nature_a' => ['nullable', 'string', Rule::in([...array_keys(IntrastatCodes::TransactionNatures), ...array_keys(IntrastatCodes::TriangularNatureLetters)])],
            'defaults.transaction_nature_b' => ['nullable', 'string', 'size:1', 'regex:/^\d$/'],
            'defaults.delivery_terms' => ['nullable', 'string', Rule::in(array_keys(IntrastatCodes::DeliveryTermGroups))],
            'defaults.transport_mode' => ['nullable', 'string', Rule::in(array_map('strval', array_keys(IntrastatCodes::TransportModes)))],
            'defaults.province_code' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'defaults.payment_country_code' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'defaults.service_code' => ['nullable', 'string', 'regex:/^\d{6}$/'],
        ];
    }
}
