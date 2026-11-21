<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Services;

use Modules\AiCognitive\Domain\Enums\SuggestionKind;

/**
 * Turns the answer of the model into the payload of a draft: only the fields of the kind survive, numbers become numbers,
 * scores are clamped to 0.00-1.00 and the contact is linked when the matcher is sure. The model's own overall confidence is
 * capped by the weakest critical field, so one doubtful field is enough to send the item to a person.
 */
final readonly class ExtractionNormalizer
{
    public function __construct(private ContactMatcher $contacts) {}

    /**
     * @param  array<string, mixed>  $raw
     * @return array{payload: array<string, mixed>, field_confidences: array<string, float>, confidence: float, amount: float|null, reasoning: string|null}
     */
    public function normalize(string $tenantId, SuggestionKind $kind, array $raw): array
    {
        $confidences = $this->confidences($raw['field_confidences'] ?? []);

        $payload = match ($kind) {
            SuggestionKind::SalesQuote => $this->quote($tenantId, $raw, $confidences),
            SuggestionKind::PurchaseOrder => $this->order($tenantId, $raw, $confidences),
            SuggestionKind::SupportTicket => $this->ticket($tenantId, $raw, $confidences),
            SuggestionKind::Contact => $this->contact($raw),
            default => [],
        };

        $overall = $this->score($raw['overall_confidence'] ?? 0.0);

        if ($confidences !== []) {
            $overall = min($overall, min($confidences));
        }

        $items = $payload['items'] ?? $payload['lines'] ?? null;

        return [
            'payload' => $payload,
            'field_confidences' => $confidences,
            'confidence' => round($overall, 2),
            'amount' => is_array($items) ? $this->total($items) : null,
            'reasoning' => isset($raw['reasoning']) && is_string($raw['reasoning']) ? mb_substr($raw['reasoning'], 0, 1000) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, float>  $confidences
     * @return array<string, mixed>
     */
    private function quote(string $tenantId, array $raw, array &$confidences): array
    {
        $customer = $this->block($raw['customer'] ?? null, ['company_name', 'email', 'vat_number']);
        $match = $this->contacts->find($tenantId, $customer['email'] ?? null, $customer['vat_number'] ?? null, $customer['company_name'] ?? null);

        if ($match !== null) {
            $confidences['customer'] = min($confidences['customer'] ?? 1.0, $match['confidence']);
        } else {
            $confidences['customer'] = min($confidences['customer'] ?? 1.0, 0.5);
        }

        return [
            'contact_id' => $match['id'] ?? null,
            'customer' => $customer,
            'currency' => $this->currency($raw['currency'] ?? null),
            'valid_until' => $this->date($raw['valid_until'] ?? null),
            'notes' => $this->text($raw['notes'] ?? null, 2000),
            'items' => $this->lines($raw['items'] ?? [], 'quantity'),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, float>  $confidences
     * @return array<string, mixed>
     */
    private function order(string $tenantId, array $raw, array &$confidences): array
    {
        $supplier = $this->block($raw['supplier'] ?? null, ['company_name', 'email', 'vat_number']);
        $match = $this->contacts->find($tenantId, $supplier['email'] ?? null, $supplier['vat_number'] ?? null, $supplier['company_name'] ?? null);
        $confidences['supplier'] = min($confidences['supplier'] ?? 1.0, $match['confidence'] ?? 0.5);

        return [
            'supplier_id' => $match['id'] ?? null,
            'supplier' => $supplier,
            'currency' => $this->currency($raw['currency'] ?? null),
            'order_date' => $this->date($raw['order_date'] ?? null),
            'expected_delivery_date' => $this->date($raw['expected_delivery_date'] ?? null),
            'notes' => $this->text($raw['notes'] ?? null, 2000),
            'lines' => $this->lines($raw['lines'] ?? [], 'quantity_ordered'),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, float>  $confidences
     * @return array<string, mixed>
     */
    private function ticket(string $tenantId, array $raw, array $confidences): array
    {
        $email = $this->text($raw['contact_email'] ?? null, 255);
        $match = $this->contacts->find($tenantId, $email, null, $this->text($raw['customer_name'] ?? null, 255));
        $priority = strtolower((string) ($raw['priority'] ?? 'medium'));

        return [
            'title' => $this->text($raw['title'] ?? null, 120),
            'description' => $this->text($raw['description'] ?? null, 10000),
            'priority' => in_array($priority, ['low', 'medium', 'high', 'critical'], true) ? $priority : 'medium',
            'customer_id' => $match['id'] ?? null,
            'contact_email' => $email,
            'contact_phone' => $this->text($raw['contact_phone'] ?? null, 40),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function contact(array $raw): array
    {
        $type = strtolower((string) ($raw['type'] ?? 'customer'));
        $country = strtoupper((string) ($raw['country'] ?? ''));

        return [
            'type' => $type === 'supplier' ? 'supplier' : 'customer',
            'company_name' => $this->text($raw['company_name'] ?? null, 255),
            'first_name' => $this->text($raw['first_name'] ?? null, 100),
            'last_name' => $this->text($raw['last_name'] ?? null, 100),
            'email' => $this->text($raw['email'] ?? null, 255),
            'phone' => $this->text($raw['phone'] ?? null, 40),
            'vat_number' => $this->text($raw['vat_number'] ?? null, 40),
            'country' => preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(mixed $raw, string $quantityKey): array
    {
        $lines = [];

        foreach (is_array($raw) ? $raw : [] as $line) {
            if (! is_array($line)) {
                continue;
            }

            $lines[] = [
                'description' => $this->text($line['description'] ?? null, 255),
                $quantityKey => $this->number($line[$quantityKey] ?? $line['quantity'] ?? null),
                'unit_price' => $this->number($line['unit_price'] ?? null),
                'tax_rate' => $this->number($line['tax_rate'] ?? null),
                'uom' => $this->text($line['uom'] ?? null, 20),
            ];
        }

        return array_slice($lines, 0, 100);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function total(array $lines): ?float
    {
        $total = 0.0;
        $known = false;

        foreach ($lines as $line) {
            $quantity = $line['quantity'] ?? $line['quantity_ordered'] ?? null;

            if (is_numeric($quantity) && is_numeric($line['unit_price'] ?? null)) {
                $total += (float) $quantity * (float) $line['unit_price'];
                $known = true;
            }
        }

        return $known ? round($total, 2) : null;
    }

    /**
     * @return array<string, float>
     */
    private function confidences(mixed $raw): array
    {
        $scores = [];

        foreach (is_array($raw) ? $raw : [] as $field => $value) {
            if (is_string($field) && is_numeric($value)) {
                $scores[$field] = $this->score($value);
            }
        }

        return $scores;
    }

    private function score(mixed $value): float
    {
        return is_numeric($value) ? round(max(0.0, min(1.0, (float) $value)), 2) : 0.0;
    }

    private function number(mixed $value): int|float|null
    {
        return is_numeric($value) ? $value + 0 : null;
    }

    private function text(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim(strip_tags((string) $value));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private function currency(mixed $value): ?string
    {
        $code = strtoupper((string) (is_scalar($value) ? $value : ''));

        return preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : null;
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string|null>
     */
    private function block(mixed $raw, array $keys): array
    {
        $raw = is_array($raw) ? $raw : [];
        $block = [];

        foreach ($keys as $key) {
            $block[$key] = $this->text($raw[$key] ?? null, 255);
        }

        return $block;
    }
}
