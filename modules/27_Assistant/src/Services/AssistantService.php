<?php

declare(strict_types=1);

namespace Modules\Assistant\Services;

use App\Models\User;
use Enterprise\AiGateway\Exceptions\ExternalAiNotAllowedException;
use Enterprise\MultiTenant\Services\ModuleAccessGate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Assistant\Catalogue\QueryCatalogue;
use Modules\Assistant\Contracts\CatalogueQuery;
use Modules\Assistant\Contracts\IntentResolver;
use Modules\Assistant\DTOs\ResolvedIntent;
use Modules\Assistant\Enums\AnswerOutcome;
use Modules\Assistant\Models\AssistantQuestionLog;
use Modules\Assistant\Models\AssistantThread;
use Throwable;

/**
 * Answers a question from the fixed catalogue: a resolver names a query, the catalogue validates its parameters, the
 * role of the caller is checked and the query runs read-only for the active tenant. Every question is logged, also when
 * it matched nothing or was refused. A model is only ever used to pick a key.
 */
final readonly class AssistantService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(
        private QueryCatalogue $catalogue,
        private KeywordIntentResolver $keywords,
        private ModelIntentResolver $model,
        private ReadOnlyQueryRunner $runner,
        private ModuleAccessGate $gate,
    ) {}

    /**
     * @return array{outcome: string, query: ?string, description: ?string, parameters: array<string, int>, rows: list<array<string, mixed>>, thread_id: string, log_id: string}
     */
    public function ask(User $user, string $tenantId, string $question, ?string $threadId = null): array
    {
        [$resolver, $intent] = $this->resolve($tenantId, $question);
        $query = $intent === null ? null : $this->catalogue->find($intent->queryKey);

        if ($query === null) {
            return $this->finish($user, $tenantId, $threadId, $question, $resolver, AnswerOutcome::Unmatched, null, [], []);
        }

        $parameters = $this->catalogue->validatedParameters($query, $intent->parameters);

        if (! $this->canRead($user, $tenantId, $query)) {
            return $this->finish($user, $tenantId, $threadId, $question, $resolver, AnswerOutcome::Forbidden, $query, $parameters, []);
        }

        try {
            $rows = $this->runner->run(static fn (): array => $query->run($tenantId, $parameters));
        } catch (Throwable $exception) {
            Log::warning("Assistant query [{$query->key()}] failed: {$exception->getMessage()}");

            return $this->finish($user, $tenantId, $threadId, $question, $resolver, AnswerOutcome::Failed, $query, $parameters, []);
        }

        return $this->finish($user, $tenantId, $threadId, $question, $resolver, AnswerOutcome::Answered, $query, $parameters, $rows);
    }

    /**
     * What this caller can ask.
     *
     * @return list<CatalogueQuery>
     */
    public function availableTo(User $user, string $tenantId): array
    {
        return array_values(array_filter($this->catalogue->all(), fn (CatalogueQuery $query): bool => $this->canRead($user, $tenantId, $query)));
    }

    private function canRead(User $user, string $tenantId, CatalogueQuery $query): bool
    {
        return $this->gate->allows($user, $tenantId, $query->module(), 'read');
    }

    /**
     * The whole catalogue goes to the resolver, so a question outside the role of the caller is logged as refused, not
     * as unmatched. A driver the tenant did not enable never sees the question: the local matcher takes over.
     *
     * @return array{0: string, 1: ?ResolvedIntent}
     */
    private function resolve(string $tenantId, string $question): array
    {
        $resolver = $this->resolverFor();

        try {
            return [$resolver->name(), $resolver->resolve($tenantId, $question, $this->catalogue->all())];
        } catch (ExternalAiNotAllowedException) {
            return [$this->keywords->name(), $this->keywords->resolve($tenantId, $question, $this->catalogue->all())];
        }
    }

    private function resolverFor(): IntentResolver
    {
        return (string) config('ai.assistant_driver', 'local') === 'local' ? $this->keywords : $this->model;
    }

    /**
     * @param  array<string, int>  $parameters
     * @param  list<array<string, mixed>>  $rows
     * @return array{outcome: string, query: ?string, description: ?string, parameters: array<string, int>, rows: list<array<string, mixed>>, thread_id: string, log_id: string}
     */
    private function finish(User $user, string $tenantId, ?string $threadId, string $question, string $resolver, AnswerOutcome $outcome, ?CatalogueQuery $query, array $parameters, array $rows): array
    {
        $thread = $this->thread($user, $tenantId, $threadId, $question);

        $log = AssistantQuestionLog::create([
            'thread_id' => $thread->id,
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'role' => $user->getRoleInTenant($tenantId),
            'question' => $question,
            'resolver' => $resolver,
            'query_key' => $query?->key(),
            'parameters' => $parameters,
            'outcome' => $outcome->value,
            'row_count' => count($rows),
        ]);

        return [
            'outcome' => $outcome->value,
            'query' => $query?->key(),
            'description' => $query?->description(),
            'parameters' => $parameters,
            'rows' => $rows,
            'thread_id' => (string) $thread->id,
            'log_id' => (string) $log->id,
        ];
    }

    /**
     * The thread of the question: the one named by the caller (already checked to be theirs), or a new one titled by the question.
     */
    private function thread(User $user, string $tenantId, ?string $threadId, string $question): AssistantThread
    {
        $thread = $threadId === null ? null : AssistantThread::query()->where('user_id', $user->id)->find($threadId);

        $thread ??= AssistantThread::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'title' => Str::limit($question, 100, ''),
        ]);

        $thread->update(['last_message_at' => now()]);

        return $thread;
    }
}
