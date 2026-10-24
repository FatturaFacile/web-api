<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A bank statement file that cannot be recognised or parsed. The message comes from `lang/en/banking.php`.
 */
class BankStatementException extends RuntimeException
{
    public const UNSUPPORTED_FORMAT = 'unsupported_format';

    public const INVALID_XML = 'invalid_xml';

    public const UNSAFE_XML = 'unsafe_xml';

    public const NO_STATEMENT = 'no_statement';

    public const INVALID_LINE_LENGTH = 'invalid_line_length';

    public const INVALID_RECORD = 'invalid_record';

    public const MISSING_HEADER = 'missing_header';

    public const MISSING_TRAILER = 'missing_trailer';

    public const RECORD_COUNT_MISMATCH = 'record_count_mismatch';

    public const EMPTY_FILE = 'empty_file';

    /**
     * @param  array<string, mixed>  $context  replacements of the message (line number, record, ...)
     */
    public static function because(string $reason, array $context = []): self
    {
        return new self(__("banking.parse_errors.{$reason}", $context));
    }
}
