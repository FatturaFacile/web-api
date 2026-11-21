<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Commands;

use Modules\AiCognitive\Domain\Enums\SuggestionKind;

/**
 * Reads an unstructured text (email, RFQ, delivery note, ticket) into a draft of the kind.
 */
final readonly class ExecuteCognitivePipelineCommand
{
    public function __construct(
        public string $tenantId,
        public ?string $userId,
        public SuggestionKind $kind,
        public string $sourceType,
        public string $text,
        public ?string $sourceReference = null,
        public string $locale = 'en',
    ) {}
}
