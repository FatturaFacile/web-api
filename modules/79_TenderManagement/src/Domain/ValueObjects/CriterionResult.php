<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Domain\ValueObjects;

/**
 * One row of the Go/No-Go matrix: a tender requirement against what the company can show.
 */
final readonly class CriterionResult
{
    public const string Financial = 'financial';

    public const string Technical = 'technical';

    public const string Qualification = 'qualification';

    public const string Certification = 'certification';

    public const string Compliance = 'compliance';

    public function __construct(
        public string $key,
        public string $category,
        public string|int|null $required,
        public string|int|null $available,
        public bool $met,
        public string|int|null $gap,
        public int $weight,
        public ?string $documentId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'category' => $this->category,
            'required' => $this->required,
            'available' => $this->available,
            'met' => $this->met,
            'gap' => $this->gap,
            'weight' => $this->weight,
            'score' => $this->met ? $this->weight : 0,
            'document_id' => $this->documentId,
        ];
    }
}
