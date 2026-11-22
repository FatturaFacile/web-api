<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Models\AccountingTemplate;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Turns a parametric template (causale contabile) and the inputs of the user into balanced journal lines, and posts
 * them through the manual journal service like any prima nota entry.
 */
final readonly class AccountingTemplateService
{
    public function __construct(private ManualJournalService $journals) {}

    /**
     * The lines a template yields for the inputs; nothing is written.
     *
     * @param  array<string, mixed>  $input  `amount` (net), `tax_rate` (percent), `accounts` (parameter key => account id or code), `cost_center_id`
     * @return array{lines: list<array<string, mixed>>, total_debit: string, total_credit: string, difference: string, is_balanced: bool}
     *
     * @throws ValidationException when a parameter is missing or an account is unknown
     */
    public function preview(AccountingTemplate $template, string $tenantId, array $input): array
    {
        $amounts = $this->amounts($template, $input);
        $accounts = $this->resolveAccounts($template, $tenantId, (array) ($input['accounts'] ?? []));
        $lines = [];
        $debit = '0';
        $credit = '0';

        foreach ($template->lines as $index => $line) {
            $value = $amounts[(string) $line['amount']] ?? '0';

            if (! Decimal::isPositive($value)) {
                continue;
            }

            $account = $accounts[$this->accountKey($line)];
            $isDebit = $line['side'] === 'debit';
            $debit = $isDebit ? bcadd($debit, $value, 4) : $debit;
            $credit = $isDebit ? $credit : bcadd($credit, $value, 4);

            $lines[] = [
                'line_number' => count($lines) + 1,
                'account_id' => (string) $account->id,
                'account_code' => $account->account_code,
                'account_name' => $account->name,
                'debit_amount' => $isDebit ? $value : '0.0000',
                'credit_amount' => $isDebit ? '0.0000' : $value,
                'description' => $line['description'] ?? null,
                'cost_center_id' => $input['cost_center_id'] ?? null,
                'template_line' => $index + 1,
            ];
        }

        return [
            'lines' => $lines,
            'total_debit' => bcadd($debit, '0', 4),
            'total_credit' => bcadd($credit, '0', 4),
            'difference' => bcsub($debit, $credit, 4),
            'is_balanced' => bccomp($debit, $credit, 4) === 0 && bccomp($debit, '0', 4) === 1,
        ];
    }

    /**
     * Posts the template as a manual journal entry of the ledger book of the template.
     *
     * @param  array<string, mixed>  $input  inputs of preview() plus `fiscal_period_id`, `posting_date`, `description`, `status`, `post_to_ledgers`
     *
     * @throws ValidationException when the lines do not balance or a parameter is missing
     */
    public function instantiate(AccountingTemplate $template, string $tenantId, array $input, ?string $actorId): JournalEntry
    {
        $preview = $this->preview($template, $tenantId, $input);

        if (! $preview['is_balanced']) {
            throw ValidationException::withMessages(['amount' => [__('general_ledger_errors.template.unbalanced')]]);
        }

        $lines = array_map(static fn (array $line): array => [
            'account_id' => $line['account_id'],
            'debit_amount' => $line['debit_amount'],
            'credit_amount' => $line['credit_amount'],
            'description' => $line['description'],
            'cost_center_id' => $line['cost_center_id'],
        ], $preview['lines']);

        return $this->journals->create([
            'tenant_id' => $tenantId,
            'fiscal_period_id' => $input['fiscal_period_id'],
            'posting_date' => $input['posting_date'],
            'description' => $input['description'] ?? $template->localizedName(app()->getLocale()),
            'ledger_type' => $template->ledger_type,
            'journal_type' => $template->journal_type,
            'status' => $input['status'] ?? 'posted',
            'post_to_ledgers' => $input['post_to_ledgers'] ?? [],
            'source_document_type' => 'accounting_template',
            'lines' => $lines,
        ], $actorId);
    }

    /**
     * The amounts a template can reference: `amount` (net), `tax` (net x rate) and `gross`.
     *
     * @param  array<string, mixed>  $input
     * @return array{amount: string, tax: string, gross: string}
     */
    private function amounts(AccountingTemplate $template, array $input): array
    {
        $hasRate = collect($template->parameters)->contains(static fn (array $parameter): bool => $parameter['type'] === 'rate');
        $errors = [];

        if (! isset($input['amount']) || ! is_numeric($input['amount']) || ! Decimal::isPositive(Decimal::normalize((string) $input['amount']))) {
            $errors['amount'] = [__('general_ledger_errors.template.amount_required')];
        }

        if ($hasRate && (! isset($input['tax_rate']) || ! is_numeric($input['tax_rate']))) {
            $errors['tax_rate'] = [__('general_ledger_errors.template.rate_required')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $net = bcadd((string) $input['amount'], '0', 4);
        // Half-up rounding of the tax to 4 decimals.
        $tax = $hasRate ? bcadd(bcdiv(bcmul($net, (string) $input['tax_rate'], 8), '100', 8), '0.00005', 4) : '0.0000';

        return ['amount' => $net, 'tax' => $tax, 'gross' => bcadd($net, $tax, 4)];
    }

    /**
     * @param  array<string, string>  $given
     * @return array<string, ChartOfAccount>
     */
    private function resolveAccounts(AccountingTemplate $template, string $tenantId, array $given): array
    {
        $resolved = [];
        $errors = [];

        foreach ($template->lines as $line) {
            $key = $this->accountKey($line);

            if (isset($resolved[$key])) {
                continue;
            }

            $reference = str_starts_with((string) $line['account'], '$') ? ($given[$key] ?? null) : (string) $line['account'];
            $account = $reference === null || $reference === '' ? null : ChartOfAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where(static fn ($query) => $query->where('id', $reference)->orWhere('account_code', $reference))
                ->first();

            if ($account === null) {
                $errors["accounts.{$key}"] = [__('general_ledger_errors.template.account_unknown', ['key' => $key])];

                continue;
            }

            $resolved[$key] = $account;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function accountKey(array $line): string
    {
        return ltrim((string) $line['account'], '$');
    }
}
