<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\Drivers;

use Enterprise\AiGateway\Contracts\AiDriverInterface;
use Enterprise\AiGateway\DTOs\AiPayload;
use Enterprise\AiGateway\DTOs\AiResponse;

/**
 * Offline driver for development, demos and tests: it reads a few fields with patterns, so nothing leaves the
 * installation. It answers with the same JSON a real model is asked for. It is not a model and its proposals are rough.
 */
final class LocalFakeDriver implements AiDriverInterface
{
    /**
     * Pattern of each field. The first capture group is the value.
     *
     * @var array<string, string>
     */
    private const array Patterns = [
        'invoice_number' => '/(?:fattura|invoice|rechnung|facture)\s*(?:n\.?|nr\.?|no\.?|numero|number)?\s*[:#]?\s*([A-Z0-9][A-Z0-9\/\-]{0,30})/iu',
        'invoice_date' => '/\b(\d{4}-\d{2}-\d{2}|\d{1,2}[.\/]\d{1,2}[.\/]\d{4})\b/',
        'total_amount' => '/(?:totale|total|gesamt|montant)[^0-9\n]{0,30}([0-9][0-9\'.,]*[0-9])/iu',
        'currency' => '/\b(EUR|CHF|USD|GBP)\b/',
        'vat_number' => '/\b((?:IT\s?\d{11})|(?:CHE[-\s]?\d{3}\.?\d{3}\.?\d{3}))\b/i',
        'iban' => '/\b([A-Z]{2}\d{2}(?:\s?[A-Z0-9]{4}){2,7}(?:\s?[A-Z0-9]{1,4})?)\b/',
    ];

    public function getName(): string
    {
        return 'local';
    }

    public function executeInference(AiPayload $payload): AiResponse
    {
        $fields = [];

        foreach (self::Patterns as $name => $pattern) {
            if (preg_match($pattern, $payload->prompt, $matches) === 1) {
                $fields[] = ['name' => $name, 'value' => trim($matches[1]), 'confidence' => 0.5];
            }
        }

        return new AiResponse(
            success: true,
            content: (string) json_encode(['fields' => $fields]),
            data: ['fields' => $fields],
            driverUsed: $this->getName(),
            modelUsed: 'local-patterns',
        );
    }

    /**
     * A fixed vector: this driver has no embedding model.
     *
     * @return array<int, float>
     */
    public function generateEmbeddings(string $text, ?string $model = null): array
    {
        return [0.0, 0.0, 0.0];
    }
}
