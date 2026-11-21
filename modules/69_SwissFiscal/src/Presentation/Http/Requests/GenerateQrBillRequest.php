<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SwissFiscal\Domain\Enums\QrReferenceType;

/** Fields of a Swiss QR-bill; the cross-field rules (IBAN family vs reference) are enforced by the QrBill value object. */
final class GenerateQrBillRequest extends FormRequest
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
            'iban' => ['required', 'string', 'max:34'],
            'currency' => ['required', Rule::in(['CHF', 'EUR'])],
            'amount' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'reference_type' => ['required', Rule::enum(QrReferenceType::class)],
            'reference' => ['nullable', 'string', 'max:40'],
            'message' => ['nullable', 'string', 'max:140'],
            'bill_information' => ['nullable', 'string', 'max:140'],
            'language' => ['nullable', Rule::in(['it', 'de', 'fr', 'en'])],
            'format' => ['nullable', Rule::in(['pdf', 'json'])],
            'creditor' => ['required', 'array'],
            'creditor.name' => ['required', 'string', 'max:70'],
            'creditor.street' => ['nullable', 'string', 'max:70'],
            'creditor.building_number' => ['nullable', 'string', 'max:16'],
            'creditor.postal_code' => ['required', 'string', 'max:16'],
            'creditor.town' => ['required', 'string', 'max:35'],
            'creditor.country' => ['required', 'string', 'size:2'],
            'debtor' => ['nullable', 'array'],
            'debtor.name' => ['required_with:debtor', 'string', 'max:70'],
            'debtor.street' => ['nullable', 'string', 'max:70'],
            'debtor.building_number' => ['nullable', 'string', 'max:16'],
            'debtor.postal_code' => ['required_with:debtor', 'string', 'max:16'],
            'debtor.town' => ['required_with:debtor', 'string', 'max:35'],
            'debtor.country' => ['required_with:debtor', 'string', 'size:2'],
        ];
    }
}
