<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Calculation;

use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;
use Modules\WithholdingRecipients\Domain\ValueObjects\FiscalCode;

/**
 * Copyright, royalties and patents (Art. 53 c. 2 lett. b TUIR): the flat-rate expense deduction is 40% for payees under 35
 * years old on the payment date (12.00% effective withholding) and 25% from 35 on (15.00%); the withholding is 20% of the
 * remainder. The age comes from the birth date, or from the Codice Fiscale when the birth date is not recorded. Out of
 * VAT scope (Art. 3 c. 4 lett. a D.P.R. 633/72), CU causal B.
 */
final class AuthorRoyaltyCalculator implements CompensationCalculator
{
    public function regime(): RecipientRegime
    {
        return RecipientRegime::AuthorRoyalty;
    }

    public function calculate(CompensationInput $input): CompensationResult
    {
        $config = (array) config('withholding_recipients.author_royalty');
        $result = new ResultBuilder($input);

        $birthDate = $input->birthDate ?? FiscalCode::tryFrom($input->taxCode)?->birthDate($input->paymentDate);
        $young = false;

        if ($birthDate === null) {
            $result->warnings[] = 'birth_date_missing';
        } else {
            $young = $birthDate->diffInYears($input->paymentDate, true) < (int) $config['young_age_limit'];
        }

        $expensePercent = $young ? $config['young_expense_percent'] : $config['standard_expense_percent'];
        $result->flatRateDeduction = $input->gross->percent($expensePercent);
        $result->taxableBase = $input->gross->minus($result->flatRateDeduction);
        $result->withholdingRate = (string) $config['withholding_rate'];
        $result->withholding = $result->taxableBase->percent($config['withholding_rate']);
        $result->nonTaxable = $result->flatRateDeduction;
        $result->nonTaxableCode = (string) $config['non_taxable_code'];
        $result->cuCausal = (string) $config['cu_causal'];
        $result->notes[] = $young ? 'royalty_under_35' : 'royalty_35_and_over';
        $result->notes[] = 'out_of_vat_scope_art_3_c4_a';

        return $result->build();
    }
}
