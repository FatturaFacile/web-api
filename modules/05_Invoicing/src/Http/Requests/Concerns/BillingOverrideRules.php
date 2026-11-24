<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests\Concerns;

/**
 * What a caller may state on an invoice generated from a source document (order, delivery notes, billing plan): the
 * dates, the notes and the payment details. Everything else comes from the source document and the invoicing service;
 * the number is always assigned by the numbering service.
 */
trait BillingOverrideRules
{
    /**
     * @return array<string, list<string>>
     */
    protected function overrideRules(): array
    {
        return [
            'date' => ['nullable', 'date'],
            'due_date' => $this->filled('date') ? ['nullable', 'date', 'after_or_equal:date'] : ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payment_method' => ['nullable', 'string', 'max:4'],
            'payment_terms' => ['nullable', 'string', 'max:4'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_iban' => ['nullable', 'string', 'max:34'],
        ];
    }
}
