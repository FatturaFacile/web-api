<?php

declare(strict_types=1);

namespace Modules\Copilot\Tools\Concerns;

use App\Models\Contact;
use App\Services\Sales\DocumentTotalsCalculator;
use Illuminate\Validation\Rule;

/**
 * What the staged sales documents (quote, invoice draft) share: the rules of the lines, the totals of the dry run and the
 * name of the customer shown on the confirmation card.
 */
trait HandlesDocumentLines
{
    /**
     * @return array<string, mixed>
     */
    protected function documentRules(string $tenantId): array
    {
        return [
            'contact_id' => ['required', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_terms' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['nullable', 'uuid', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.uom' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function documentSchemaProperties(): array
    {
        return [
            'contact_id' => ['type' => 'string', 'description' => 'UUID of the customer contact'],
            'currency' => ['type' => 'string', 'description' => 'ISO 4217 code, e.g. EUR or CHF'],
            'payment_terms' => ['type' => 'string'],
            'notes' => ['type' => 'string'],
            'items' => [
                'type' => 'array',
                'description' => 'Document lines',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'string', 'description' => 'UUID of a product, optional'],
                        'description' => ['type' => 'string'],
                        'quantity' => ['type' => 'number'],
                        'unit_price' => ['type' => 'number', 'description' => 'Net unit price'],
                        'tax_rate' => ['type' => 'number', 'description' => 'VAT rate in percent'],
                        'discount_percentage' => ['type' => 'number'],
                    ],
                    'required' => ['description', 'quantity', 'unit_price', 'tax_rate'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{items: array<int, array<string, mixed>>, details: array<string, mixed>}
     */
    protected function documentTotals(array $arguments): array
    {
        $items = array_values((array) $arguments['items']);
        $totals = (new DocumentTotalsCalculator)->calculateDecimal($items);

        return [
            'items' => $items,
            'details' => [
                'customer' => $this->customerName((string) $arguments['contact_id']),
                'currency' => strtoupper((string) ($arguments['currency'] ?? 'EUR')),
                'line_count' => count($items),
                'total_net' => $totals['total_net'],
                'total_tax' => $totals['total_tax'],
                'total_gross' => $totals['total_gross'],
                'lines' => array_map(static fn (array $item, int $index): array => [
                    'description' => (string) $item['description'],
                    'quantity' => $totals['items'][$index]['quantity'],
                    'unit_price' => $totals['items'][$index]['unit_price'],
                    'discount_percentage' => $totals['items'][$index]['discount_percentage'],
                    'tax_rate' => $totals['items'][$index]['tax_rate'],
                    'line_total' => $totals['items'][$index]['line_total'],
                ], $items, array_keys($items)),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<string>
     */
    protected function lineEditablePaths(array $arguments): array
    {
        $paths = ['notes'];

        foreach (array_keys((array) $arguments['items']) as $index) {
            $paths[] = "items.{$index}.quantity";
            $paths[] = "items.{$index}.discount_percentage";
        }

        return $paths;
    }

    private function customerName(string $contactId): string
    {
        $contact = Contact::query()->find($contactId);

        if ($contact === null) {
            return $contactId;
        }

        $name = $contact->company_name ?: trim($contact->first_name.' '.$contact->last_name);

        return $name !== '' ? $name : $contactId;
    }
}
