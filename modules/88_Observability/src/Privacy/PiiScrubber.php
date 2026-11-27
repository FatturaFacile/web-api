<?php

declare(strict_types=1);

namespace Modules\Observability\Privacy;

use Stringable;
use Throwable;

/**
 * Masks personal data before it reaches a log line, a span or the SQL profiler: IBANs, AHV/AVS numbers, Codice Fiscale, VAT and UID numbers,
 * e-mails, phone numbers and credentials inside free text, and every value stored under a sensitive key (names, addresses, secrets).
 */
final class PiiScrubber
{
    public const string MASK = '[REDACTED]';

    private const int MAX_DEPTH = 8;

    /**
     * Applied in this order: the more specific shapes first, so that an IBAN is not read as a VAT number.
     *
     * @var array<string, string>
     */
    private const array PATTERNS = [
        '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/' => '[TOKEN]',
        '/\bBearer\s+[A-Za-z0-9._~+\/=-]{8,}/i' => 'Bearer [TOKEN]',
        '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i' => '[EMAIL]',
        '/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){3,7}(?: ?[A-Z0-9]{1,3})?\b/' => '[IBAN]',
        '/\b756[.\s]?\d{4}[.\s]?\d{4}[.\s]?\d{2}\b/' => '[AVS]',
        '/\b[A-Z]{6}\d{2}[A-EHLMPR-T]\d{2}[A-Z]\d{3}[A-Z]\b/i' => '[CF]',
        '/\bCHE[-\s]?\d{3}[.\s]?\d{3}[.\s]?\d{3}(?:\s?(?:MWST|TVA|IVA))?/i' => '[UID]',
        '/\bIT\s?\d{11}\b/i' => '[VAT]',
        '/\b(?:ATU\d{8}|(?:BE|BG|CY|CZ|DE|DK|EE|EL|ES|FI|FR|HR|HU|IE|LT|LU|LV|MT|NL|PL|PT|RO|SE|SI|SK)\d{8,12})\b/' => '[VAT]',
        '/(?<![\w.])\+\d{1,3}[\s().-]?\d(?:[\s().-]?\d){7,13}\b/' => '[PHONE]',
    ];

    private const string SENSITIVE_KEY = '/(pass(word|wd)?|secret|token|authorization|api[_-]?key|cookie|session|credential|private[_-]?key|iban|\bbic\b|swift|avs|ahv|ssn|codice[_-]?fiscale|fiscal[_-]?code|tax[_-]?(id|number)|p[_-]?iva|partita|uid[_-]?number|vat[_-]?(number|id)|(^|[_-])vat$|(first|last|full|given|family|legal|company|business|customer|supplier|display|contact)[_-]?name|^name$|e?mail|phone|mobile|telephone|(^|[_-])(address|addresses|street|postal|zip)([_-]|$)|(home|billing|shipping|client|ip)[_-]?address|birth|dob)/i';

    public function scrubString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        foreach (self::PATTERNS as $pattern => $replacement) {
            $value = (string) preg_replace($pattern, $replacement, $value);
        }

        return $value;
    }

    public function isSensitiveKey(string $key): bool
    {
        return preg_match(self::SENSITIVE_KEY, $key) === 1;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  bool  $flatten  Replaces objects by a plain description (exceptions keep their class, scrubbed message and the frames without arguments).
     * @return array<array-key, mixed>
     */
    public function scrubArray(array $data, bool $flatten = false, int $depth = 0): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $value !== null && $value !== '' && $this->isSensitiveKey($key)) {
                $clean[$key] = self::MASK;

                continue;
            }

            $clean[$key] = $this->scrubValue($value, $flatten, $depth + 1);
        }

        return $clean;
    }

    public function scrubValue(mixed $value, bool $flatten = false, int $depth = 0): mixed
    {
        if (is_string($value)) {
            return $this->scrubString($value);
        }

        if (is_array($value)) {
            return $depth >= self::MAX_DEPTH ? '[TRUNCATED]' : $this->scrubArray($value, $flatten, $depth);
        }

        if ($value instanceof Throwable && $flatten) {
            return $this->describeThrowable($value);
        }

        if (is_object($value) && $flatten) {
            return $value instanceof Stringable ? $this->scrubString((string) $value) : '[object '.$value::class.']';
        }

        return $value;
    }

    /**
     * @return array{class: class-string<Throwable>, message: string, code: int|string, file: string, line: int, trace: list<string>}
     */
    public function describeThrowable(Throwable $throwable): array
    {
        $trace = [];

        foreach (array_slice($throwable->getTrace(), 0, 15) as $frame) {
            $trace[] = ($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'].' @ '.basename((string) ($frame['file'] ?? '?')).':'.($frame['line'] ?? 0);
        }

        return [
            'class' => $throwable::class,
            'message' => $this->scrubString($throwable->getMessage()),
            'code' => $throwable->getCode(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'trace' => $trace,
        ];
    }
}
