<?php

declare(strict_types=1);

namespace Modules\Treasury\Services\Reconciliation;

use App\Models\BankStatementLine;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Treasury\Models\PaymentLine;

/**
 * Deterministic, ordered matching of a statement line with the open receivables (customer invoices) or payables
 * (received supplier invoices) of the tenant. Three rules, each with the confidence it earns:
 *
 * 1. structured reference: the QR reference (QRR, 27 digits) or ISO 11649 creditor reference (SCOR) of the line is the
 *    one of the document (or of the payment order that paid it), 100 when the amount settles it;
 * 2. exact amount + counterparty: the balance matches within the tolerance and the line names the counterparty by
 *    VAT number / fiscal code (95), IBAN (92) or a normalised name (85), less when several documents tie;
 * 3. fuzzy description + invoice number: the invoice number appears in the remittance text (separators and case are
 *    ignored, one typo is forgiven) and the amount settles within the bank-fee and exchange tolerances.
 *
 * The engine only proposes; applying a proposal is the job of {@see ReconciliationProposalService}.
 */
final class SmartReconciliationEngine
{
    private const int MAX_OPEN_DOCUMENTS = 5000;

    private const int MAX_PROPOSALS = 5;

    /**
     * @var array<string, Collection<int, Invoice|PassiveInvoice>>
     */
    private array $open = [];

    /**
     * @var array<string, Collection<string, Contact>>
     */
    private array $contacts = [];

    public function __construct(private readonly AmountAssessor $assessor) {}

    public function reset(): void
    {
        $this->open = [];
        $this->contacts = [];
    }

    /**
     * @return list<ProposalCandidate> best first, at most five
     */
    public function propose(BankStatementLine $line, SmartSettings $settings): array
    {
        $customers = $line->isCredit();
        $documents = $this->openDocuments((string) $line->tenant_id, $customers);

        if ($documents->isEmpty()) {
            return [];
        }

        /** @var array<string, ProposalCandidate> $best */
        $best = [];
        $keep = function (ProposalCandidate $candidate) use (&$best): void {
            $id = (string) $candidate->document->id;

            if (! isset($best[$id]) || $candidate->confidence > $best[$id]->confidence || ($candidate->confidence === $best[$id]->confidence && $candidate->rank() < $best[$id]->rank())) {
                $best[$id] = $candidate;
            }
        };

        foreach ($this->byStructuredReference($line, $documents, $customers, $settings) as $candidate) {
            $keep($candidate);
        }

        foreach ($this->byAmountAndCounterparty($line, $documents, $customers, $settings) as $candidate) {
            $keep($candidate);
        }

        foreach ($this->byFuzzyInvoice($line, $documents, $customers, $settings) as $candidate) {
            $keep($candidate);
        }

        $candidates = array_values(array_filter($best, static fn (ProposalCandidate $candidate): bool => $candidate->confidence >= $settings->minConfidence));
        usort($candidates, static fn (ProposalCandidate $a, ProposalCandidate $b): int => [$b->confidence, $a->rank()] <=> [$a->confidence, $b->rank()]);

        return array_slice($candidates, 0, self::MAX_PROPOSALS);
    }

    /**
     * @param  Collection<int, Invoice|PassiveInvoice>  $documents
     * @return list<ProposalCandidate>
     */
    private function byStructuredReference(BankStatementLine $line, Collection $documents, bool $customers, SmartSettings $settings): array
    {
        $reference = $this->compact((string) $line->qr_reference);
        $matched = collect();

        if ($customers && $reference !== '') {
            $matched = $documents->filter(fn (Invoice|PassiveInvoice $document): bool => $document instanceof Invoice
                && ($this->compact((string) $document->qr_reference) === $reference || $this->compact((string) $document->qrBillData?->scor_reference) === $reference));
        } elseif (! $customers) {
            $keys = array_values(array_filter([$reference, $this->compact((string) $line->reference), $this->compact((string) $line->entry_reference)]));
            $invoiceIds = $keys === [] ? [] : PaymentLine::query()
                ->whereNotNull('invoice_id')
                ->where(fn (Builder $query) => $query->whereIn('structured_creditor_ref', $keys)->orWhereIn('end_to_end_id', $keys))
                ->pluck('invoice_id')->all();
            $matched = $documents->filter(static fn (Invoice|PassiveInvoice $document): bool => in_array((string) $document->id, array_map('strval', $invoiceIds), true));
        }

        $candidates = [];

        foreach ($matched as $document) {
            $assessment = $this->assess($line, $document, $settings);

            if ($assessment !== null) {
                $candidates[] = new ProposalCandidate($document, $this->type($document), ProposalCandidate::RULE_STRUCTURED_REFERENCE, $assessment->isComplete() ? 100 : 90, $assessment, ['structured_reference']);
            }
        }

        return $candidates;
    }

    /**
     * @param  Collection<int, Invoice|PassiveInvoice>  $documents
     * @return list<ProposalCandidate>
     */
    private function byAmountAndCounterparty(BankStatementLine $line, Collection $documents, bool $customers, SmartSettings $settings): array
    {
        $text = strtoupper((string) $line->description.' '.(string) $line->reference);
        $iban = strtoupper((string) preg_replace('/\s+/', '', (string) $line->counterpart_iban));
        $name = (string) ($line->counterpart_name ?: '');
        $found = [];

        foreach ($documents as $document) {
            $assessment = $this->assess($line, $document, $settings);

            if ($assessment === null || $assessment->kind !== Assessment::EXACT) {
                continue;
            }

            $contact = $this->contact((string) $document->tenant_id, $this->contactId($document));

            if ($contact === null) {
                continue;
            }

            [$confidence, $reason] = match (true) {
                $this->mentions($text, $contact->vat_number) || $this->mentions($text, $contact->fiscal_code) => [95, 'tax_id'],
                $iban !== '' && $this->compactIban((string) $contact->iban) === $iban => [92, 'iban'],
                $this->nameMatches($name !== '' ? $name : (string) $line->description, $contact, $settings->nameSimilarity) => [85, 'name'],
                default => [0, ''],
            };

            if ($confidence > 0) {
                $found[] = [$document, $assessment, $confidence, $reason, $contact->id];
            }
        }

        $perContact = array_count_values(array_map(static fn (array $row): string => (string) $row[4], $found));
        $candidates = [];

        foreach ($found as [$document, $assessment, $confidence, $reason, $contactId]) {
            $reasons = ['exact_amount', $reason];

            if ($perContact[(string) $contactId] > 1) {
                $confidence -= 10;
                $reasons[] = 'ambiguous';
            }

            $candidates[] = new ProposalCandidate($document, $this->type($document), ProposalCandidate::RULE_AMOUNT_COUNTERPARTY, $confidence, $assessment, $reasons);
        }

        return $candidates;
    }

    /**
     * @param  Collection<int, Invoice|PassiveInvoice>  $documents
     * @return list<ProposalCandidate>
     */
    private function byFuzzyInvoice(BankStatementLine $line, Collection $documents, bool $customers, SmartSettings $settings): array
    {
        $text = $this->compact((string) $line->description.' '.(string) $line->reference);
        $tokens = preg_split('/[^A-Z0-9]+/', strtoupper((string) $line->description.' '.(string) $line->reference), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $candidates = [];

        if ($text === '') {
            return [];
        }

        foreach ($documents as $document) {
            $number = $this->compact((string) $document->invoice_number);

            if (strlen($number) < 4) {
                continue;
            }

            [$base, $reason] = match (true) {
                str_contains($text, $number) => [85, 'invoice_number'],
                $this->oneTypo($number, $tokens) => [70, 'invoice_number_approximate'],
                default => [0, ''],
            };

            if ($base === 0) {
                continue;
            }

            $assessment = $this->assess($line, $document, $settings);

            if ($assessment === null) {
                continue;
            }

            $reasons = [$reason];
            $confidence = $base;

            match ($assessment->kind) {
                Assessment::FEE_TOLERANCE => [$confidence -= 5, $reasons[] = 'bank_fee_tolerance'],
                Assessment::FX => [$confidence -= 5, $reasons[] = 'fx_difference'],
                Assessment::PARTIAL => [$confidence -= 15, $reasons[] = 'partial_payment'],
                default => null,
            };

            $contact = $this->contact((string) $document->tenant_id, $this->contactId($document));

            if ($contact !== null && $line->counterpart_name !== null && $this->nameMatches((string) $line->counterpart_name, $contact, $settings->nameSimilarity)) {
                $confidence += 5;
                $reasons[] = 'name';
            }

            $candidates[] = new ProposalCandidate($document, $this->type($document), ProposalCandidate::RULE_FUZZY_INVOICE, min($confidence, 94), $assessment, $reasons);
        }

        return $candidates;
    }

    private function assess(BankStatementLine $line, Invoice|PassiveInvoice $document, SmartSettings $settings): ?Assessment
    {
        return $this->assessor->assess($line, (string) $document->currency, $this->due($document), $settings);
    }

    private function due(Invoice|PassiveInvoice $document): string
    {
        return $document instanceof PassiveInvoice
            ? bcsub((string) $document->amountDue(), (string) $document->total_paid, 2)
            : bcadd((string) $document->outstandingAmount(), '0', 2);
    }

    /**
     * @return Collection<int, Invoice|PassiveInvoice>
     */
    private function openDocuments(string $tenantId, bool $customers): Collection
    {
        $key = $tenantId.($customers ? ':c' : ':s');

        return $this->open[$key] ??= $customers
            ? Invoice::query()->with('qrBillData')
                ->where('tenant_id', $tenantId)
                ->where('payment_status', '!=', 'paid')
                ->whereNotIn('invoice_type', ['proforma', 'credit_note'])
                ->limit(self::MAX_OPEN_DOCUMENTS)->get()
            : PassiveInvoice::query()
                ->where('tenant_id', $tenantId)
                ->where('payment_status', '!=', 'paid')
                ->where('document_type', '!=', 'TD04')
                ->whereNotIn('approval_status', PassiveInvoice::PaymentBlockingStatuses)
                ->limit(self::MAX_OPEN_DOCUMENTS)->get();
    }

    private function type(Invoice|PassiveInvoice $document): string
    {
        return $document instanceof PassiveInvoice ? 'passive_invoice' : 'invoice';
    }

    private function contactId(Invoice|PassiveInvoice $document): ?string
    {
        return (string) ($document instanceof PassiveInvoice ? $document->supplier_id : $document->contact_id) ?: null;
    }

    private function contact(string $tenantId, ?string $id): ?Contact
    {
        if ($id === null) {
            return null;
        }

        $this->contacts[$tenantId] ??= Contact::query()->where('tenant_id', $tenantId)->get()->keyBy('id');

        return $this->contacts[$tenantId]->get($id);
    }

    private function compact(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }

    private function compactIban(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    private function mentions(string $text, ?string $identifier): bool
    {
        $identifier = strtoupper(trim((string) $identifier));

        return strlen($identifier) >= 8 && preg_match('/(?<![A-Z0-9])'.preg_quote($identifier, '/').'(?![A-Z0-9])/', $text) === 1;
    }

    /**
     * Two names are the same counterparty when their normalised forms (no legal form, no punctuation, case folded)
     * are similar above the configured threshold.
     */
    private function nameMatches(string $name, Contact $contact, float $threshold): bool
    {
        $left = $this->normalizeName($name);
        $legal = $this->normalizeName((string) ($contact->company_name ?: trim($contact->first_name.' '.$contact->last_name)));

        if ($left === '' || $legal === '') {
            return false;
        }

        if (str_contains($left, $legal) || str_contains($legal, $left)) {
            return min(strlen($left), strlen($legal)) >= 4;
        }

        similar_text($left, $legal, $percent);

        return $percent / 100 >= $threshold;
    }

    private function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = (string) preg_replace('/\b(s\.?\s?r\.?\s?l\.?s?|s\.?\s?p\.?\s?a\.?|s\.?\s?a\.?\s?s\.?|s\.?\s?n\.?\s?c\.?|ag|gmbh|sagl|sa|ltd|inc|srl|spa)\b/u', ' ', $name);

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name));
    }

    /**
     * Whether some token of the text is one edit (substitution) away from the number, after removing separators.
     *
     * @param  list<string>  $tokens
     */
    private function oneTypo(string $number, array $tokens): bool
    {
        if (strlen($number) < 5) {
            return false;
        }

        $windows = [];

        foreach ($tokens as $index => $token) {
            $windows[] = $token;

            if (isset($tokens[$index + 1])) {
                $windows[] = $token.$tokens[$index + 1];
            }

            if (isset($tokens[$index + 2])) {
                $windows[] = $token.$tokens[$index + 1].$tokens[$index + 2];
            }
        }

        foreach ($windows as $window) {
            if (strlen($window) === strlen($number) && levenshtein($window, $number) === 1) {
                return true;
            }
        }

        return false;
    }
}
