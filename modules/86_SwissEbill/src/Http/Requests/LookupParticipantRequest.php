<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SwissEbill\Application\Dto\ParticipantLookupCriteria;
use Modules\SwissEbill\Domain\Support\SwissUid;

/**
 * One identifier to look up in the participant directory: UID, email, IBAN / QR-IBAN or eBill participant id.
 */
final class LookupParticipantRequest extends FormRequest
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
            'lookup_type' => ['required', Rule::in(ParticipantLookupCriteria::TYPES)],
            'lookup_value' => ['required', 'string', 'max:120', function (string $attribute, mixed $value, \Closure $fail): void {
                $type = $this->input('lookup_type');

                $valid = match ($type) {
                    'uid' => SwissUid::isValid((string) $value),
                    'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                    'iban' => preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', strtoupper((string) preg_replace('/\s+/', '', (string) $value))) === 1,
                    default => true,
                };

                if (! $valid) {
                    $fail('invalid_lookup_value');
                }
            }],
            'force_refresh' => ['sometimes', 'boolean'],
            'customer_id' => ['nullable', 'uuid'],
        ];
    }
}
