<?php

declare(strict_types=1);

namespace Modules\Governance\Services;

use App\Contracts\Governance\DocumentSegregationGuard;
use App\Enums\SegregatedDocument;
use App\Exceptions\SodDocumentConflictException;
use Modules\Governance\Models\GovSodDocumentRule;

/**
 * Per-document segregation of duties. A company that has no rule for a kind of document keeps the checks the document
 * module always made (payments, purchase orders and journals refuse their own author as approver); a rule adds the
 * same check where it did not exist (payroll runs), extends it to everybody who prepared the document, or switches it
 * to a warning.
 */
final readonly class DocumentSodService implements DocumentSegregationGuard
{
    public function __construct(private GovernanceLedger $ledger) {}

    /**
     * @param  list<string>  $preparerIds
     *
     * @throws SodDocumentConflictException
     */
    public function assertDifferentPeople(string $tenantId, SegregatedDocument $document, string $documentId, string $actorId, array $preparerIds): void
    {
        if (! in_array($actorId, $preparerIds, true)) {
            return;
        }

        $rule = GovSodDocumentRule::query()->where('tenant_id', $tenantId)->where('document_type', $document->value)->where('is_active', true)->first();

        if ($rule === null) {
            return;
        }

        if ($rule->severity === 'warn') {
            $this->ledger->record('governance.sod_document.warned', $rule, ['document_type' => $document->value, 'document_id' => $documentId, 'user_id' => $actorId]);

            return;
        }

        throw new SodDocumentConflictException(__('governance_errors.sod_document.conflict', ['document' => __('governance_errors.sod_document.documents.'.$document->value)]));
    }

    /**
     * Every kind of document with its rule, or the defaults while the company has not configured it.
     *
     * @return list<array{document_type: string, severity: string, is_active: bool, configured: bool}>
     */
    public function rulesOf(string $tenantId): array
    {
        $rules = GovSodDocumentRule::query()->where('tenant_id', $tenantId)->get()->keyBy('document_type');

        return array_map(static function (string $type) use ($rules): array {
            $rule = $rules->get($type);

            return ['document_type' => $type, 'severity' => $rule->severity ?? 'block', 'is_active' => $rule->is_active ?? false, 'configured' => $rule !== null];
        }, SegregatedDocument::values());
    }

    public function configure(string $tenantId, SegregatedDocument $document, string $severity, bool $isActive): GovSodDocumentRule
    {
        $rule = GovSodDocumentRule::withTrashed()->firstOrNew(['tenant_id' => $tenantId, 'document_type' => $document->value]);
        $rule->fill(['severity' => $severity, 'is_active' => $isActive]);
        $rule->deleted_at = null;
        $rule->save();
        $this->ledger->record('governance.sod_document_rule.configured', $rule, ['document_type' => $document->value, 'severity' => $severity, 'is_active' => $isActive]);

        return $rule;
    }
}
