<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Domain\ValueObjects;

use Modules\AiCognitive\Domain\Enums\SuggestionKind;

/**
 * What a pipeline hands to the review queue: the proposed action with its scores and the evidence behind it.
 */
final readonly class SuggestionProposal
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, float>  $fieldConfidences
     * @param  list<array<string, mixed>>  $citations
     */
    public function __construct(
        public string $tenantId,
        public SuggestionKind $kind,
        public string $sourceType,
        public array $payload,
        public float $confidence,
        public ?string $sourceReference = null,
        public ?string $sourceText = null,
        public array $fieldConfidences = [],
        public ?float $amount = null,
        public ?string $reasoning = null,
        public array $citations = [],
        public string $priority = 'normal',
        public ?string $provider = null,
        public ?string $model = null,
        public ?string $userId = null,
    ) {}
}
