<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Contact;
use App\Models\ContactAddress;
use App\Rules\ValidFiscalIdentifier;
use App\Services\Crm\ContactHierarchyService;
use App\Services\Crm\Fiscal\FiscalIdentifiers;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Formal fiscal validation (Italy and Switzerland) and hierarchy / address rules shared by contact creation and
 * update. Saving is blocked (422) when the Partita IVA, Codice Fiscale, UID or SDI code are formally wrong.
 */
trait ValidatesContactFiscalData
{
    /**
     * Canonicalises fiscal identifiers before validation, so "it 01234567897" and "che 123.456.789" are accepted.
     */
    protected function normalizeFiscalInput(): void
    {
        $merge = [];

        foreach (['vat_number', 'fiscal_code', 'sdi_code', 'uid_number'] as $key) {
            $value = $this->input($key);

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            $merge[$key] = match ($key) {
                'vat_number' => strtoupper((string) preg_replace('/[\s.\-]/', '', $value)),
                'fiscal_code' => FiscalIdentifiers::normalizeFiscalCode($value),
                'uid_number' => FiscalIdentifiers::normalizeSwissUid($value) ?? strtoupper($value),
                default => strtoupper($value),
            };

            if ($merge[$key] === '') {
                $merge[$key] = null;
            }
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fiscalRules(string $country, ?Contact $current = null): array
    {
        $vat = ['nullable', 'string', 'max:30'];
        $vatValue = (string) $this->input('vat_number', $current->vat_number ?? '');

        if ($country === 'IT') {
            $vat[] = new ValidFiscalIdentifier('it_vat');
        } elseif ($country === 'CH' && str_starts_with(strtoupper($vatValue), 'CHE')) {
            $vat[] = new ValidFiscalIdentifier('ch_uid');
        }

        return [
            'vat_number' => $vat,
            'fiscal_code' => ['nullable', 'string', 'max:16', ...($country === 'IT' ? [new ValidFiscalIdentifier('it_fiscal_code')] : [])],
            'uid_number' => ['nullable', 'string', 'max:20', new ValidFiscalIdentifier('ch_uid')],
            'sdi_code' => ['nullable', 'string', 'size:7', new ValidFiscalIdentifier('sdi_code')],
            'pec' => ['nullable', 'email'],
            'parent_id' => ['nullable', 'uuid', $this->parentRule($current)],
            'hierarchy_type' => ['nullable', 'string', Rule::in(['holding', 'subsidiary', 'branch'])],
            'addresses' => ['nullable', 'array'],
            'addresses.*.type' => ['required', 'string', Rule::in(ContactAddress::TYPES)],
            'addresses.*.label' => ['nullable', 'string', 'max:100'],
            'addresses.*.recipient_name' => ['nullable', 'string', 'max:255'],
            'addresses.*.street' => ['required', 'string', 'max:255'],
            'addresses.*.house_number' => ['nullable', 'string', 'max:20'],
            'addresses.*.postal_code' => ['required', 'string', 'max:20'],
            'addresses.*.city' => ['required', 'string', 'max:255'],
            'addresses.*.state_canton' => ['nullable', 'string', 'max:50'],
            'addresses.*.country' => ['required', 'string', 'size:2'],
            'addresses.*.is_default' => ['nullable', 'boolean'],
            'addresses.*.notes' => ['nullable', 'string'],
        ];
    }

    private function parentRule(?Contact $current): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($current): void {
            if ($value === null) {
                return;
            }

            // The tenant global scope makes foreign-tenant parents invisible.
            if (! Contact::whereKey($value)->exists()) {
                $fail('The selected parent company does not exist.');

                return;
            }

            if ($current !== null && app(ContactHierarchyService::class)->wouldCreateCycle($current, (string) $value)) {
                $fail('The selected parent company would create a loop in the hierarchy.');
            }
        };
    }
}
