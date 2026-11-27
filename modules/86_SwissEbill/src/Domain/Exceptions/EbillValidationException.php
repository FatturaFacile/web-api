<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Exceptions;

/**
 * The invoice, the biller or the recipient lack something the eBill payload needs. Every violation names the field and a
 * translated code (swissEbill.violation.<code>), so the client can show a complete pre-flight list instead of the first problem.
 */
final class EbillValidationException extends EbillException
{
    /**
     * @param  list<array{code: string, field: string}>  $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('payload_invalid', 'The eBill payload is not valid: '.implode(', ', array_map(static fn (array $v): string => $v['field'].'='.$v['code'], $violations)), 422);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extensions(): array
    {
        return ['violations' => $this->violations];
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_values(array_unique(array_column($this->violations, 'code')));
    }
}
