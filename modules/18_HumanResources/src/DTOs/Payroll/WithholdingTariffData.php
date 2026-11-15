<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs\Payroll;

use Modules\HumanResources\Models\Payroll\WithholdingTariff;

/**
 * Resolved withholding tax tariff (canton + effective tariff code + validity) with its monthly brackets.
 */
final readonly class WithholdingTariffData
{
    /**
     * @param  list<array{income_from_rappen: int, income_to_rappen: int|null, rate_ppm: int, fixed_amount_rappen: int}>  $brackets
     */
    public function __construct(
        public string $canton,
        public string $tariffCode,
        public string $validFrom,
        public array $brackets,
        public ?string $id = null,
    ) {}

    public static function fromModel(WithholdingTariff $tariff): self
    {
        $brackets = [];

        foreach ($tariff->brackets as $bracket) {
            $brackets[] = [
                'income_from_rappen' => $bracket->income_from_rappen,
                'income_to_rappen' => $bracket->income_to_rappen,
                'rate_ppm' => $bracket->rate_ppm,
                'fixed_amount_rappen' => $bracket->fixed_amount_rappen,
            ];
        }

        return new self(
            canton: $tariff->canton,
            tariffCode: $tariff->tariff_code,
            validFrom: $tariff->valid_from->toDateString(),
            brackets: $brackets,
            id: (string) $tariff->id,
        );
    }

    /**
     * Bracket whose inclusive income range contains the taxable income.
     *
     * @return array{income_from_rappen: int, income_to_rappen: int|null, rate_ppm: int, fixed_amount_rappen: int}|null
     */
    public function bracketFor(int $taxableIncomeRappen): ?array
    {
        foreach ($this->brackets as $bracket) {
            $upperBound = $bracket['income_to_rappen'];

            if ($taxableIncomeRappen >= $bracket['income_from_rappen']
                && ($upperBound === null || $taxableIncomeRappen <= $upperBound)) {
                return $bracket;
            }
        }

        return null;
    }
}
