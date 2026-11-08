<?php

declare(strict_types=1);

namespace Modules\Childcare\Models;

use Carbon\CarbonImmutable;

/**
 * A subsidy rule of a canton, as parameters entered by the kindergarten. The module ships no rule: a rule is applied
 * only after an authorised person has verified it against its official source, and changing its parameters withdraws
 * the verification.
 *
 * @property string $id
 * @property string $canton
 * @property string $method
 * @property array<string, mixed> $parameters
 * @property CarbonImmutable $valid_from
 * @property ?CarbonImmutable $valid_to
 * @property ?string $source_reference
 * @property ?CarbonImmutable $verified_at
 */
final class SubsidyRule extends ChildcareRecord
{
    /** @var list<string> */
    public const array Methods = ['fixed_per_day', 'income_brackets_per_day', 'income_brackets_percentage'];

    protected $table = 'cc_subsidy_rules';

    protected static function booted(): void
    {
        self::saving(static function (self $rule): void {
            if ($rule->exists && $rule->isDirty(['method', 'parameters', 'canton', 'valid_from', 'valid_to']) && ! $rule->isDirty('verified_at')) {
                $rule->verified_at = null;
                $rule->verified_by = null;
            }
        });
    }

    protected function casts(): array
    {
        return ['parameters' => 'array', 'valid_from' => 'immutable_date', 'valid_to' => 'immutable_date', 'verified_at' => 'immutable_datetime'];
    }

    /**
     * Whether the rule was verified against its official source; only verified rules are used to bill.
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
