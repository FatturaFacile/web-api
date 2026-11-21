<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\AiCognitive\Domain\Contracts\AiProviderGatewayInterface;
use Modules\AiCognitive\Domain\Enums\AiTaskType;
use Modules\AiCognitive\Domain\Enums\DataClassification;
use Modules\AiCognitive\Domain\Exceptions\AiCognitiveException;
use Modules\AiCognitive\Domain\ValueObjects\AiCompletionRequest;
use Modules\Copilot\DTOs\ToolContext;
use Modules\Copilot\Semantic\ExecuteGovernedSemanticQuery;
use Modules\Copilot\Semantic\SemanticEntityRegistry;
use Modules\Copilot\Semantic\SemanticQueryException;
use Modules\Copilot\Services\PromptSanitizer;

/**
 * Natural language analytics without model-written SQL. The model only chooses an entity, fields, filters and metrics from
 * the closed registry of the Copilot; the structured query is then run by the governed executor (tenant scoped, parameter
 * bound, restricted fields refused). Relative dates ("overdue by more than 15 days") are turned into concrete dates here, by
 * code, not by the model. Read-only by construction: there is no write path in the executor.
 */
final readonly class SafeQueryTranslatorService
{
    public function __construct(
        private AiProviderGatewayInterface $gateway,
        private PromptLibrary $prompts,
        private SemanticEntityRegistry $entities,
        private ExecuteGovernedSemanticQuery $executor,
        private PromptSanitizer $sanitizer,
    ) {}

    /**
     * @return array{query: array<string, mixed>, explanation: string|null, data: array<string, mixed>, visualization: array<string, mixed>|null, citations: list<array<string, mixed>>}
     *
     * @throws AiCognitiveException when the question cannot be turned into a permitted query
     * @throws AuthorizationException when the user may not read the entity
     */
    public function answer(User $user, string $tenantId, string $question, string $locale = 'en'): array
    {
        $question = $this->prompts->defuse($this->sanitizer->prompt($question));

        $catalogue = [];

        foreach ($this->entities->all() as $entity) {
            $fields = [];

            foreach ($entity->fields as $name => $field) {
                $fields[] = "{$name}:{$field->type}";
            }

            $catalogue[] = "{$entity->key} ({$entity->description}): ".implode(', ', $fields);
        }

        $result = $this->gateway->complete(new AiCompletionRequest(
            $tenantId,
            AiTaskType::QueryTranslation,
            $this->prompts->system($tenantId, 'query.translate', $locale)."\nENTITIES:\n".implode("\n", $catalogue)."\nToday is ".now()->toDateString().'.',
            "<user_question>\n{$question}\n</user_question>",
            DataClassification::Internal,
            (string) $user->id,
            maxTokens: 600,
            expectJson: true,
        ));

        $spec = $result->json() ?? throw new AiCognitiveException('The question could not be understood. Rephrase it.');
        $explanation = is_string($spec['explanation'] ?? null) ? mb_substr((string) $spec['explanation'], 0, 500) : null;
        $query = $this->clean($spec);

        try {
            $outcome = $this->executor->execute(new ToolContext($user, $tenantId, $locale), $query);
        } catch (SemanticQueryException $exception) {
            throw new AiCognitiveException($exception->getMessage());
        }

        return ['query' => $query, 'explanation' => $explanation, 'data' => $outcome->data, 'visualization' => $outcome->visualization, 'citations' => $outcome->citations];
    }

    /**
     * Keeps only the keys of the query language and resolves the relative date into a filter.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    public function clean(array $spec): array
    {
        $query = array_intersect_key($spec, array_flip(['entity', 'select', 'filters', 'metrics', 'dimensions', 'order_by', 'limit', 'chart']));
        $query['filters'] = array_values(array_filter((array) ($query['filters'] ?? []), is_array(...)));
        $relative = $spec['relative_dates'] ?? null;

        if (is_array($relative) && isset($relative['field'], $relative['older_than_days']) && is_numeric($relative['older_than_days'])) {
            $days = max(0, min(3650, (int) $relative['older_than_days']));
            $query['filters'][] = ['field' => (string) $relative['field'], 'op' => 'lt', 'value' => CarbonImmutable::today()->subDays($days)->toDateString()];
        }

        $query['limit'] = max(1, min((int) ($query['limit'] ?? 50), (int) config('ai_cognitive.query.max_rows', 200)));

        return $query;
    }
}
