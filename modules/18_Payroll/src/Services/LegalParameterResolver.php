<?php

declare(strict_types=1);

namespace Modules\Payroll\Services;

use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\PayrollLegalParameter;

/**
 * Finds the parameters of a topic for a year: the row of the year or the closest earlier one, with a warning, and a
 * company row before the generic row of the same year, as PayrollParameterResolver does for the annual values.
 */
final class LegalParameterResolver
{
    public function resolve(string $topic, int $year): LegalParameterSet
    {
        $row = PayrollLegalParameter::query()
            ->where('topic', $topic)
            ->where('year', '<=', $year)
            ->orderByDesc('year')
            ->orderByRaw('tenant_id IS NULL')
            ->first();

        if ($row === null) {
            throw new PayrollException("Parametri legali {$topic} mancanti per il {$year}.");
        }

        return new LegalParameterSet(
            topic: $topic,
            year: $row->year,
            values: $row->parameters,
            source: $row->source,
            warnings: $row->year === $year ? [] : ["Parametri {$topic} del {$row->year} usati per il {$year}: verificarli e aggiornarli."],
        );
    }
}
