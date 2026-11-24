<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Domain\Exceptions;

use RuntimeException;

/**
 * A rule of the Italian payroll pre-processing was violated (locked period, unbalanced summary, unmatched payslip).
 */
class ItalianPayrollException extends RuntimeException {}
