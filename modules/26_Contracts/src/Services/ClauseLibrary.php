<?php

declare(strict_types=1);

namespace Modules\Contracts\Services;

use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Contracts\Models\ContractClause;
use Modules\Contracts\Models\ContractClauseFallback;
use Modules\Contracts\Models\ContractClauseVersion;

/**
 * The clause library: clauses keep every wording as an immutable version, may be approved for use or retired, and carry
 * ranked fallback wordings for negotiation.
 */
final class ClauseLibrary
{
    /**
     * Create a clause with its first version.
     *
     * @param  array{code: string, title: string, category?: string|null, body: string, change_note?: string|null}  $data
     */
    public function create(array $data, ?string $userId): ContractClause
    {
        return DB::transaction(function () use ($data, $userId): ContractClause {
            $clause = ContractClause::query()->create([
                'code' => $data['code'],
                'title' => $data['title'],
                'category' => $data['category'] ?? null,
                'status' => 'draft',
                'current_version_no' => 0,
            ]);

            return $this->appendVersion($clause, $data['body'], $data['change_note'] ?? null, $userId);
        });
    }

    /**
     * Add a new version; earlier versions stay readable and unchanged.
     */
    public function addVersion(ContractClause $clause, string $body, ?string $changeNote, ?string $userId): ContractClause
    {
        if ($clause->status === 'retired') {
            throw ValidationException::withMessages(['status' => __('contracts.clause.retired_no_versions')]);
        }

        return DB::transaction(fn (): ContractClause => $this->appendVersion($clause, $body, $changeNote, $userId));
    }

    /**
     * Move the clause between draft, approved and retired. Only a clause with a version can be approved.
     */
    public function transition(ContractClause $clause, string $status): ContractClause
    {
        if ($status === 'approved' && $clause->current_version_no < 1) {
            throw ValidationException::withMessages(['status' => __('contracts.clause.no_version_to_approve')]);
        }

        if ($clause->status === 'retired' && $status !== 'retired') {
            throw ValidationException::withMessages(['status' => __('contracts.clause.retired_no_reuse')]);
        }

        $clause->update(['status' => $status]);

        return $clause->refresh();
    }

    /**
     * Add a fallback wording; the rank is the next free one unless given.
     *
     * @param  array{title: string, body: string, rank?: int|null, requires_approval?: bool}  $data
     */
    public function addFallback(ContractClause $clause, array $data): ContractClauseFallback
    {
        return DB::transaction(function () use ($clause, $data): ContractClauseFallback {
            TransactionLock::acquire('contract-clause-fallback:'.$clause->getKey());
            $rank = $data['rank'] ?? ((int) $clause->fallbacks()->max('rank') + 1);

            if ($clause->fallbacks()->where('rank', $rank)->exists()) {
                throw ValidationException::withMessages(['rank' => __('contracts.clause.rank_used')]);
            }

            return $clause->fallbacks()->create([
                'rank' => $rank,
                'title' => $data['title'],
                'body' => $data['body'],
                'requires_approval' => (bool) ($data['requires_approval'] ?? false),
            ]);
        });
    }

    /**
     * The text of the clause: a fallback wording when a rank is given, otherwise the current version.
     */
    public function resolveBody(ContractClause $clause, ?int $fallbackRank = null): string
    {
        if ($fallbackRank !== null) {
            $fallback = $clause->fallbacks()->where('rank', $fallbackRank)->first();

            if ($fallback === null) {
                throw ValidationException::withMessages(['fallback_rank' => __('contracts.clause.no_fallback_rank')]);
            }

            return $fallback->body;
        }

        $version = $clause->versions()->where('version_no', $clause->current_version_no)->first();

        return $version?->body ?? '';
    }

    private function appendVersion(ContractClause $clause, string $body, ?string $changeNote, ?string $userId): ContractClause
    {
        TransactionLock::acquire('contract-clause:'.$clause->getKey());
        $next = (int) ContractClauseVersion::query()->where('clause_id', $clause->getKey())->max('version_no') + 1;

        ContractClauseVersion::query()->create([
            'clause_id' => $clause->getKey(),
            'version_no' => $next,
            'body' => $body,
            'change_note' => $changeNote,
            'created_by' => $userId,
        ]);

        $clause->update(['current_version_no' => $next]);

        return $clause->refresh();
    }
}
