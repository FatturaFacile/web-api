<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Modules\AiCognitive\Domain\Contracts\AiProviderGatewayInterface;
use Modules\AiCognitive\Domain\Enums\AiTaskType;
use Modules\AiCognitive\Domain\Enums\SuggestionKind;
use Modules\AiCognitive\Domain\Exceptions\AiCognitiveException;
use Modules\AiCognitive\Domain\ValueObjects\AiCompletionRequest;
use Modules\AiCognitive\Domain\ValueObjects\SuggestionProposal;
use Modules\AiCognitive\Infrastructure\Models\AiSuggestion;
use Modules\Copilot\Embeddings\LocalHashEmbeddingProvider;
use Modules\Copilot\Services\PromptSanitizer;

/**
 * The smart accounting copilot: it proposes the account, the VAT code and the cost center of a bank transaction or an
 * expense receipt (only from the tenant's own lists, so a model can never invent a code), and it proposes matches between
 * unmatched bank transactions and open invoices by amount, reference, name similarity and a local semantic similarity
 * (feature hashing, no data leaves the installation). Partial and grouped payments are detected and flagged as such.
 */
final readonly class AccountingCopilotService
{
    public function __construct(
        private AiProviderGatewayInterface $gateway,
        private PromptLibrary $prompts,
        private AutomationPolicy $policy,
        private SuggestionService $suggestions,
        private PromptSanitizer $sanitizer,
        private LocalHashEmbeddingProvider $embedding,
    ) {}

    /**
     * Statutory VAT rates that may appear on a document of the country.
     *
     * @return list<float>
     */
    public static function vatRates(string $country): array
    {
        return strtoupper($country) === 'CH' ? [8.1, 3.8, 2.6, 0.0] : [22.0, 10.0, 5.0, 4.0, 0.0];
    }

    /**
     * @throws AiCognitiveException
     */
    public function suggestCoding(string $tenantId, ?string $userId, string $description, ?float $amount, ?string $counterparty, string $sourceType, ?string $sourceReference, string $locale = 'en'): AiSuggestion
    {
        $country = strtoupper((string) (DB::table('tenants')->where('id', $tenantId)->value('country') ?? 'CH'));
        $accounts = DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('is_active', true)->where('type', 'expense')->orderBy('code')->limit(120)->get(['id', 'code', 'name']);
        $vatCodes = DB::table('vat_codes')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('is_active', true)->where('country_iso', $country)->limit(40)->get(['id', 'code', 'rate', 'description']);
        $centers = DB::table('cost_centers')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('is_active', true)->limit(40)->get(['id', 'code', 'name']);

        if ($accounts->isEmpty() || $vatCodes->isEmpty()) {
            throw new AiCognitiveException('The chart of accounts or the VAT codes of the company are empty, so there is nothing to choose from.');
        }

        $rule = $this->policy->rule($tenantId, SuggestionKind::AccountingCoding->workflow());
        $text = $this->prompts->defuse($this->sanitizer->prompt($description));

        $lists = "ACCOUNTS:\n".$accounts->map(fn (object $row): string => "{$row->code} {$row->name}")->implode("\n")
            ."\nVAT CODES (country {$country}, statutory rates: ".implode(', ', self::vatRates($country))."):\n".$vatCodes->map(fn (object $row): string => "{$row->code} {$row->rate}%")->implode("\n")
            ."\nCOST CENTERS:\n".$centers->map(fn (object $row): string => "{$row->code} {$row->name}")->implode("\n");

        $result = $this->gateway->complete(new AiCompletionRequest(
            $tenantId,
            AiTaskType::AccountingCoding,
            $this->prompts->system($tenantId, 'accounting.coding', $locale),
            "{$lists}\n<source_text>\nCounterparty: ".($counterparty ?? 'unknown').'; amount: '.($amount === null ? 'unknown' : number_format($amount, 2, '.', ''))."; text: {$text}\n</source_text>",
            $rule['classification'],
            $userId,
            maxTokens: 500,
            expectJson: true,
            preferredProvider: $rule['preferred_provider'],
        ));

        $raw = $result->json() ?? throw new AiCognitiveException('The model did not return a readable coding.');

        // The model may only pick from the lists: an unknown code is no answer.
        $account = $accounts->firstWhere('code', (string) ($raw['account_code'] ?? ''));
        $vat = $vatCodes->firstWhere('code', (string) ($raw['vat_code'] ?? ''));
        $center = $centers->firstWhere('code', (string) ($raw['cost_center_code'] ?? ''));

        $scores = (array) ($raw['field_confidences'] ?? []);
        $confidences = [
            'account' => $account === null ? 0.0 : $this->score($scores['account'] ?? $raw['overall_confidence'] ?? 0),
            'vat' => $vat === null ? 0.0 : $this->score($scores['vat'] ?? $raw['overall_confidence'] ?? 0),
        ];

        if ($center !== null) {
            $confidences['cost_center'] = $this->score($scores['cost_center'] ?? $raw['overall_confidence'] ?? 0);
        }

        return $this->suggestions->propose(new SuggestionProposal(
            $tenantId,
            SuggestionKind::AccountingCoding,
            $sourceType,
            [
                'description' => mb_substr($text, 0, 300),
                'gl_account_id' => $account->id ?? null,
                'account_code' => $account->code ?? null,
                'vat_code_id' => $vat->id ?? null,
                'vat_code' => $vat->code ?? null,
                'vat_rate' => $vat === null ? null : (float) $vat->rate,
                'cost_center_id' => $center->id ?? null,
            ],
            round(min($this->score($raw['overall_confidence'] ?? 0), ...array_values($confidences)), 2),
            $sourceReference,
            $text,
            $confidences,
            $amount,
            is_string($raw['reasoning'] ?? null) ? mb_substr((string) $raw['reasoning'], 0, 1000) : null,
            [['type' => $sourceType, 'reference' => $sourceReference]],
            'normal',
            $result->provider,
            $result->model,
            $userId,
        ));
    }

    /**
     * Proposes matches for the unmatched bank transactions of the tenant. Returns the suggestions it created or found.
     *
     * @return list<AiSuggestion>
     */
    public function suggestReconciliation(string $tenantId, ?string $userId, int $limit = 200): array
    {
        $tolerance = (float) config('ai_cognitive.reconciliation.amount_tolerance', 0.05);
        $window = (int) config('ai_cognitive.reconciliation.date_window_days', 45);

        $transactions = DB::table('bank_transactions')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('status', 'unmatched')->where('amount', '>', 0)->orderByDesc('booking_date')->limit($limit)->get();
        $invoices = DB::table('invoices')
            ->join('contacts', 'contacts.id', '=', 'invoices.contact_id')
            ->where('invoices.tenant_id', $tenantId)->whereNull('invoices.deleted_at')
            ->whereIn('invoices.payment_status', ['unpaid', 'partial', 'partially_paid', 'overdue'])
            ->get(['invoices.id', 'invoices.invoice_number', 'invoices.contact_id', 'invoices.date', 'invoices.currency', 'invoices.total_gross', 'invoices.total_paid', 'contacts.company_name', 'contacts.last_name']);

        $created = [];

        foreach ($transactions as $transaction) {
            $match = $this->bestMatch($transaction, $invoices->all(), $tolerance, $window);

            if ($match === null) {
                continue;
            }

            $created[] = $this->suggestions->propose(new SuggestionProposal(
                $tenantId,
                SuggestionKind::ReconciliationMatch,
                'bank_transaction',
                [
                    'bank_transaction_id' => $transaction->id,
                    'invoice_id' => $match['invoice_id'],
                    'invoice_ids' => $match['invoice_ids'],
                    'match_type' => $match['type'],
                    'amount' => (float) $transaction->amount,
                    'open_amount' => $match['open'],
                ],
                $match['score'],
                (string) $transaction->id.':'.$match['invoice_id'],
                (string) $transaction->description,
                $match['signals'],
                (float) $transaction->amount,
                $match['reasoning'],
                array_map(static fn (string $id): array => ['type' => 'invoice', 'reference' => $id], $match['invoice_ids']),
                $match['score'] >= (float) config('ai_cognitive.reconciliation.auto_threshold', 0.9) ? 'normal' : 'low',
                null,
                null,
                $userId,
            ));
        }

        return $created;
    }

    /**
     * @param  list<object>  $invoices
     * @return array{invoice_id: string, invoice_ids: list<string>, type: string, score: float, open: float, signals: array<string, float>, reasoning: string}|null
     */
    private function bestMatch(object $transaction, array $invoices, float $tolerance, int $window): ?array
    {
        $amount = (float) $transaction->amount;
        $haystack = mb_strtolower($transaction->description.' '.($transaction->reference ?? ''));
        $best = null;
        $byContact = [];

        foreach ($invoices as $invoice) {
            if ($invoice->currency !== $transaction->currency) {
                continue;
            }

            $open = round((float) $invoice->total_gross - (float) $invoice->total_paid, 2);

            if ($open <= 0) {
                continue;
            }

            $byContact[$invoice->contact_id][] = [$invoice, $open];
            $name = mb_strtolower((string) ($invoice->company_name ?? $invoice->last_name ?? ''));

            $signals = [
                'amount' => abs($open - $amount) <= $tolerance ? 1.0 : ($amount < $open ? 0.4 : 0.0),
                'reference' => $invoice->invoice_number !== '' && str_contains($haystack, mb_strtolower((string) $invoice->invoice_number)) ? 1.0 : 0.0,
                'name' => $name === '' ? 0.0 : $this->nameSimilarity($haystack, $name),
                'semantic' => $this->cosine($this->embedding->embed($haystack), $this->embedding->embed($name.' '.$invoice->invoice_number)),
                'date' => abs((int) round((strtotime((string) $transaction->booking_date) - strtotime((string) $invoice->date)) / 86400)) <= $window ? 1.0 : 0.0,
            ];

            $score = 0.45 * $signals['amount'] + 0.3 * $signals['reference'] + 0.12 * $signals['name'] + 0.08 * $signals['semantic'] + 0.05 * $signals['date'];

            // Neither the amount nor the reference fits: not a candidate, whatever the name says.
            if ($signals['amount'] < 1.0 && $signals['reference'] < 1.0 && $score < 0.5) {
                continue;
            }

            if ($best === null || $score > $best['score']) {
                $type = $signals['amount'] >= 1.0 ? 'exact' : 'partial';
                $best = ['invoice_id' => (string) $invoice->id, 'invoice_ids' => [(string) $invoice->id], 'type' => $type, 'score' => round(min($score, 1.0), 2), 'open' => $open, 'signals' => array_map(static fn (float $value): float => round($value, 2), $signals), 'reasoning' => ''];
            }
        }

        // Grouped payment: the amount is the sum of two or three open invoices of one customer.
        if ($best === null || $best['type'] !== 'exact') {
            foreach ($byContact as $rows) {
                $group = $this->subsetSum($rows, $amount, $tolerance);

                if ($group !== null) {
                    $ids = array_map(static fn (array $row): string => (string) $row[0]->id, $group);
                    $referenced = count(array_filter($group, static fn (array $row): bool => str_contains($haystack, mb_strtolower((string) $row[0]->invoice_number)))) > 0;
                    $score = $referenced ? 0.93 : 0.7;

                    if ($best === null || $score > $best['score']) {
                        $best = ['invoice_id' => $ids[0], 'invoice_ids' => $ids, 'type' => 'grouped', 'score' => $score, 'open' => round(array_sum(array_column($group, 1)), 2), 'signals' => ['amount' => 1.0, 'reference' => $referenced ? 1.0 : 0.0], 'reasoning' => ''];
                    }
                }
            }
        }

        if ($best === null) {
            return null;
        }

        $best['reasoning'] = match ($best['type']) {
            'exact' => 'The amount equals the open amount of the invoice'.($best['signals']['reference'] >= 1.0 ? ' and the payment text names the invoice number.' : '.'),
            'grouped' => 'The amount equals the sum of '.count($best['invoice_ids']).' open invoices of the same customer (grouped payment).',
            default => 'The payment is lower than the open amount of the invoice (partial payment).',
        };

        return $best;
    }

    /**
     * @param  list<array{0: object, 1: float}>  $rows
     * @return list<array{0: object, 1: float}>|null
     */
    private function subsetSum(array $rows, float $amount, float $tolerance): ?array
    {
        $count = count($rows);
        $rows = array_slice($rows, 0, 12);
        $count = min($count, 12);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (abs($rows[$i][1] + $rows[$j][1] - $amount) <= $tolerance) {
                    return [$rows[$i], $rows[$j]];
                }

                for ($k = $j + 1; $k < $count; $k++) {
                    if (abs($rows[$i][1] + $rows[$j][1] + $rows[$k][1] - $amount) <= $tolerance) {
                        return [$rows[$i], $rows[$j], $rows[$k]];
                    }
                }
            }
        }

        return null;
    }

    private function nameSimilarity(string $haystack, string $name): float
    {
        $tokens = array_filter(preg_split('/[^\p{L}\d]+/u', $name) ?: [], static fn (string $token): bool => mb_strlen($token) >= 3);

        if ($tokens === []) {
            return 0.0;
        }

        $hits = count(array_filter($tokens, static fn (string $token): bool => str_contains($haystack, $token)));
        similar_text($haystack, $name, $percent);

        return max($hits / count($tokens), min($percent / 100, 1.0) * 0.6);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;

        foreach ($a as $index => $value) {
            $dot += $value * ($b[$index] ?? 0.0);
        }

        return max(0.0, min(1.0, $dot));
    }

    private function score(mixed $value): float
    {
        return is_numeric($value) ? round(max(0.0, min(1.0, (float) $value)), 2) : 0.0;
    }
}
