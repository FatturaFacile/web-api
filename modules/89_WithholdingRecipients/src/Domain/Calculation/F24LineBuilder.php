<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Calculation;

use Carbon\CarbonImmutable;
use Modules\WithholdingRecipients\Domain\Enums\Jurisdiction;
use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;

/**
 * Lines of the Modello F24 a settlement feeds: the IRPEF withholding (1040 for self-employment, royalties, occasional and
 * sports work; 1038 for agents' commissions) and the INPS Gestione Separata contributions (C10). Only Italian regimes pay
 * through the F24; amounts are zero-suppressed.
 */
final class F24LineBuilder
{
    /**
     * @return list<array{tax_code: string, reference_month: string, reference_year: int, amount: string, section: string}>
     */
    public function lines(RecipientRegime $regime, CompensationResult $result, CarbonImmutable $paymentDate, int $taxYear): array
    {
        if ($regime->jurisdiction() !== Jurisdiction::Italy) {
            return [];
        }

        $lines = [];
        $month = $paymentDate->format('m');

        if ($result->withholding->isPositive()) {
            $lines[] = [
                'tax_code' => (string) config($regime === RecipientRegime::Agent25Bis ? 'withholding_recipients.f24.agent_code' : 'withholding_recipients.f24.self_employed_code'),
                'reference_month' => $month,
                'reference_year' => $taxYear,
                'amount' => $result->withholding->toDecimal(),
                'section' => 'erario',
            ];
        }

        if ($result->inpsTotal->isPositive()) {
            $lines[] = [
                'tax_code' => (string) config('withholding_recipients.occasional.f24_inps_code'),
                'reference_month' => $month,
                'reference_year' => $taxYear,
                'amount' => $result->inpsTotal->toDecimal(),
                'section' => 'inps',
            ];
        }

        return $lines;
    }
}
