<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Services;

use Modules\AiCognitive\Domain\Contracts\AiProviderGatewayInterface;
use Modules\AiCognitive\Domain\Enums\AiTaskType;
use Modules\AiCognitive\Domain\Enums\SuggestionKind;
use Modules\AiCognitive\Domain\Exceptions\AiCognitiveException;
use Modules\AiCognitive\Domain\ValueObjects\AiCompletionRequest;
use Modules\AiCognitive\Domain\ValueObjects\SuggestionProposal;
use Modules\AiCognitive\Infrastructure\Models\AiSuggestion;
use Modules\Copilot\Services\PromptSanitizer;

/**
 * Unstructured text to a draft ERP entity: a free-text email, an RFQ, a supplier delivery note or a support message goes to
 * the model, comes back as JSON with field level confidences and enters the review queue (or is executed at once when the
 * tenant policy allows it). The source text is wrapped in data delimiters and defused before it reaches the model.
 */
final readonly class CognitivePipelineService
{
    public function __construct(
        private AiProviderGatewayInterface $gateway,
        private PromptLibrary $prompts,
        private AutomationPolicy $policy,
        private ExtractionNormalizer $normalizer,
        private SuggestionService $suggestions,
        private PromptSanitizer $sanitizer,
    ) {}

    /**
     * @throws AiCognitiveException
     */
    public function run(string $tenantId, ?string $userId, SuggestionKind $kind, string $sourceType, string $text, ?string $sourceReference = null, string $locale = 'en'): AiSuggestion
    {
        if (! $kind->isDraftEntity()) {
            throw new AiCognitiveException('The extraction pipeline only drafts quotes, purchase orders, tickets and contacts.');
        }

        $rule = $this->policy->rule($tenantId, $kind->workflow());

        if (! $rule['enabled']) {
            throw new AiCognitiveException('This automation workflow is switched off for the company.');
        }

        $source = $this->prompts->defuse(mb_substr($this->sanitizer->prompt($text), 0, 12000));

        if ($source === '') {
            throw new AiCognitiveException('There is no text to read.');
        }

        $result = $this->gateway->complete(new AiCompletionRequest(
            $tenantId,
            AiTaskType::Extraction,
            $this->prompts->system($tenantId, 'extraction.'.$kind->value, $locale),
            "<source_text>\n{$source}\n</source_text>",
            $rule['classification'],
            $userId,
            maxTokens: 1500,
            expectJson: true,
            preferredProvider: $rule['preferred_provider'],
        ));

        $raw = $result->json();

        if ($raw === null) {
            throw new AiCognitiveException('The model did not return a readable proposal.');
        }

        $normalized = $this->normalizer->normalize($tenantId, $kind, $raw);

        return $this->suggestions->propose(new SuggestionProposal(
            $tenantId,
            $kind,
            $sourceType,
            $normalized['payload'],
            $normalized['confidence'],
            $sourceReference,
            $source,
            $normalized['field_confidences'],
            $normalized['amount'],
            $normalized['reasoning'],
            [['type' => $sourceType, 'reference' => $sourceReference]],
            $normalized['confidence'] <= 0.5 ? 'low' : 'normal',
            $result->provider,
            $result->model,
            $userId,
        ));
    }
}
