<?php

declare(strict_types=1);

namespace Modules\Treasury\Services\Reconciliation;

use App\Models\Invoice;
use App\Models\PassiveInvoice;

/**
 * One document a statement line could settle, with the rule that found it and the evidence behind the confidence.
 */
final readonly class ProposalCandidate
{
    public const string RULE_STRUCTURED_REFERENCE = 'structured_reference';

    public const string RULE_AMOUNT_COUNTERPARTY = 'amount_counterparty';

    public const string RULE_FUZZY_INVOICE = 'fuzzy_invoice';

    /**
     * @param  list<string>  $reasons  codes of the evidence (translated by the client)
     */
    public function __construct(
        public Invoice|PassiveInvoice $document,
        public string $documentType,
        public string $rule,
        public int $confidence,
        public Assessment $assessment,
        public array $reasons,
    ) {}

    /**
     * Priority of the rule when two proposals share the confidence (lower wins).
     */
    public function rank(): int
    {
        return match ($this->rule) {
            self::RULE_STRUCTURED_REFERENCE => 1,
            self::RULE_AMOUNT_COUNTERPARTY => 2,
            default => 3,
        };
    }
}
