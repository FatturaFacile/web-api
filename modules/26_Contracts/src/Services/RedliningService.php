<?php

declare(strict_types=1);

namespace Modules\Contracts\Services;

use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Contracts\Enums\ContractKind;
use Modules\Contracts\Models\ContractComment;
use Modules\Contracts\Models\ContractDocumentVersion;
use Modules\Contracts\Models\ContractNegotiationRound;
use Modules\Contracts\Models\ContractTemplate;

/**
 * Redlining of a contract text: numbered document versions, the diff between two of them, review comments and the
 * negotiation rounds (one open round at a time) the versions belong to.
 */
final class RedliningService
{
    public function __construct(
        private readonly ContractRegister $register,
        private readonly TemplateAssembler $assembler,
        private readonly TextDiff $diff,
    ) {}

    /**
     * Store a new version of the text; it joins the open negotiation round when there is one.
     *
     * @param  array<string, mixed>|null  $variables
     */
    public function addVersion(ContractKind $kind, string $contractId, string $body, string $source, ?string $userId, ?string $notes = null, ?string $templateId = null, ?array $variables = null): ContractDocumentVersion
    {
        $this->register->find($kind, $contractId);

        return DB::transaction(function () use ($kind, $contractId, $body, $source, $userId, $notes, $templateId, $variables): ContractDocumentVersion {
            TransactionLock::acquire('contract-document:'.$kind->value.':'.$contractId);

            $next = (int) ContractDocumentVersion::query()->where('contract_kind', $kind->value)->where('contract_id', $contractId)->max('version_no') + 1;
            $round = $this->openRound($kind, $contractId);

            return ContractDocumentVersion::query()->create([
                'contract_kind' => $kind->value,
                'contract_id' => $contractId,
                'version_no' => $next,
                'body' => $body,
                'source' => $source,
                'template_id' => $templateId,
                'variables' => $variables,
                'round_id' => $round?->getKey(),
                'author_id' => $userId,
                'notes' => $notes,
                'body_hash' => hash('sha256', $body),
            ]);
        });
    }

    /**
     * Assemble the text from a template and store it as the next version.
     *
     * @param  array<string, scalar|null>  $values
     * @param  list<string>  $includeOptional
     * @param  array<string, int>  $fallbackRanks
     */
    public function addVersionFromTemplate(ContractKind $kind, string $contractId, ContractTemplate $template, array $values, array $includeOptional, array $fallbackRanks, ?string $userId): ContractDocumentVersion
    {
        if ($template->contract_kind !== null && $template->contract_kind !== $kind->value) {
            throw ValidationException::withMessages(['template_id' => __('contracts.template.wrong_kind', ['kind' => $template->contract_kind])]);
        }

        $body = $this->assembler->assemble($template, $values, $includeOptional, $fallbackRanks);

        return $this->addVersion($kind, $contractId, $body, 'template', $userId, null, (string) $template->getKey(), $values);
    }

    /**
     * Diff between two versions of the same contract, version "from" being the older text.
     *
     * @return array{from: int, to: int, added: int, removed: int, lines: list<array{type: string, text: string}>}
     */
    public function compare(ContractDocumentVersion $from, ContractDocumentVersion $to): array
    {
        if ($from->contract_kind !== $to->contract_kind || $from->contract_id !== $to->contract_id) {
            throw ValidationException::withMessages(['to' => __('contracts.redline.same_contract')]);
        }

        $lines = $this->diff->lines($from->body, $to->body);

        return [
            'from' => $from->version_no,
            'to' => $to->version_no,
            'added' => count(array_filter($lines, static fn (array $line): bool => $line['type'] === 'added')),
            'removed' => count(array_filter($lines, static fn (array $line): bool => $line['type'] === 'removed')),
            'lines' => $lines,
        ];
    }

    /**
     * @param  array{body: string, line_number?: int|null}  $data
     */
    public function comment(ContractDocumentVersion $version, array $data, ?string $userId): ContractComment
    {
        return ContractComment::query()->create([
            'document_version_id' => $version->getKey(),
            'author_id' => $userId,
            'line_number' => $data['line_number'] ?? null,
            'body' => $data['body'],
            'status' => 'open',
        ]);
    }

    public function resolveComment(ContractComment $comment, ?string $userId): ContractComment
    {
        if ($comment->status === 'resolved') {
            throw ValidationException::withMessages(['status' => __('contracts.redline.comment_resolved')]);
        }

        $comment->update(['status' => 'resolved', 'resolved_by' => $userId, 'resolved_at' => now()]);

        return $comment->refresh();
    }

    /**
     * Open the next negotiation round; a round is closed before the next one starts.
     */
    public function openNewRound(ContractKind $kind, string $contractId, ?string $summary, ?string $userId): ContractNegotiationRound
    {
        $this->register->find($kind, $contractId);

        return DB::transaction(function () use ($kind, $contractId, $summary, $userId): ContractNegotiationRound {
            TransactionLock::acquire('contract-round:'.$kind->value.':'.$contractId);

            if ($this->openRound($kind, $contractId) !== null) {
                throw ValidationException::withMessages(['round' => __('contracts.redline.round_open')]);
            }

            return ContractNegotiationRound::query()->create([
                'contract_kind' => $kind->value,
                'contract_id' => $contractId,
                'round_no' => (int) ContractNegotiationRound::query()->where('contract_kind', $kind->value)->where('contract_id', $contractId)->max('round_no') + 1,
                'status' => 'open',
                'summary' => $summary,
                'opened_by' => $userId,
            ]);
        });
    }

    public function closeRound(ContractNegotiationRound $round, string $outcome, ?string $summary): ContractNegotiationRound
    {
        if ($round->status !== 'open') {
            throw ValidationException::withMessages(['round' => __('contracts.redline.round_closed')]);
        }

        $round->update(['status' => 'closed', 'outcome' => $outcome, 'summary' => $summary ?? $round->summary, 'closed_at' => now()]);

        return $round->refresh();
    }

    private function openRound(ContractKind $kind, string $contractId): ?ContractNegotiationRound
    {
        return ContractNegotiationRound::query()->where('contract_kind', $kind->value)->where('contract_id', $contractId)->where('status', 'open')->first();
    }
}
