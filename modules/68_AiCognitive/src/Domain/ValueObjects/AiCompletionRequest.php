<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Domain\ValueObjects;

use Modules\AiCognitive\Domain\Enums\AiTaskType;
use Modules\AiCognitive\Domain\Enums\DataClassification;

/**
 * One model call as the layer sees it. The text is the plain ERP text: masking and routing happen inside the gateway.
 */
final readonly class AiCompletionRequest
{
    public function __construct(
        public string $tenantId,
        public AiTaskType $task,
        public string $system,
        public string $prompt,
        public DataClassification $classification = DataClassification::Internal,
        public ?string $userId = null,
        public int $maxTokens = 1024,
        public float $temperature = 0.1,
        public bool $expectJson = false,
        public bool $cacheable = true,
        public ?string $preferredProvider = null,
    ) {}
}
