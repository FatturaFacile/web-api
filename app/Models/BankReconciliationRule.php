<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BankReconciliationRuleFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A configurable reconciliation rule: when a statement line that matches no invoice satisfies every condition of the
 * rule (direction, amount within a tolerance or a range, reference, description, counterparty IBAN or name), it is
 * categorised and posted on the rule's account. Rules are tried by ascending priority; the first that matches wins.
 */
#[UseFactory(BankReconciliationRuleFactory::class)]
class BankReconciliationRule extends Model
{
    /** @use HasFactory<BankReconciliationRuleFactory> */
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    /**
     * Conditions of a rule: at least one must be set, otherwise the rule would categorise every line.
     *
     * @var list<string>
     */
    public const array CONDITIONS = ['expected_amount', 'min_amount', 'max_amount', 'reference_contains', 'description_contains', 'counterparty_iban', 'counterparty_name_contains'];

    protected $fillable = [
        'tenant_id',
        'name',
        'priority',
        'is_active',
        'direction',
        'expected_amount',
        'amount_tolerance',
        'min_amount',
        'max_amount',
        'reference_contains',
        'description_contains',
        'counterparty_iban',
        'counterparty_name_contains',
        'category',
        'ledger_account_code',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
            'expected_amount' => 'decimal:2',
            'amount_tolerance' => 'decimal:2',
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
        ];
    }

    /**
     * Whether a statement line satisfies every condition of the rule. Amounts are compared as decimal strings.
     */
    public function matches(BankStatementLine $line): bool
    {
        if ($this->direction !== null && $this->direction !== $line->direction) {
            return false;
        }

        if ($line->isCredit() && $this->category !== BankStatementLine::CATEGORY_INTERNAL_TRANSFER) {
            return false;
        }

        $amount = bcadd((string) $line->amount, '0', 2);

        if ($this->expected_amount !== null) {
            $difference = bcsub($amount, (string) $this->expected_amount, 2);

            if (bccomp(ltrim($difference, '-'), (string) $this->amount_tolerance, 2) === 1) {
                return false;
            }
        }

        if ($this->min_amount !== null && bccomp($amount, (string) $this->min_amount, 2) === -1) {
            return false;
        }

        if ($this->max_amount !== null && bccomp($amount, (string) $this->max_amount, 2) === 1) {
            return false;
        }

        if ($this->counterparty_iban !== null && self::normalizeIban($this->counterparty_iban) !== self::normalizeIban((string) $line->counterpart_iban)) {
            return false;
        }

        return self::contains($line->reference, $this->reference_contains)
            && self::contains($line->description, $this->description_contains)
            && self::contains($line->counterpart_name, $this->counterparty_name_contains);
    }

    /**
     * An IBAN without spaces, in upper case.
     */
    public static function normalizeIban(string $iban): string
    {
        return strtoupper(str_replace(' ', '', $iban));
    }

    private static function contains(?string $text, ?string $needle): bool
    {
        return $needle === null || $needle === '' || mb_stripos((string) $text, $needle) !== false;
    }
}
