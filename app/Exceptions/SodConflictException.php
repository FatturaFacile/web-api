<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * A role or permission assignment would give one member two conflicting duties and no exception covers them.
 */
final class SodConflictException extends DomainException
{
    /**
     * @param  list<array{rule_code: string, rule_title: string, duty_a: string, duty_b: string}>  $conflicts
     */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct('The assignment conflicts with segregation of duties rule '.implode(', ', array_unique(array_column($conflicts, 'rule_code'))).'.');
    }
}
