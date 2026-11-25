<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Domain\ValueObjects;

/**
 * A finding of a compliance check. The code is the suffix of the translation key tenderManagement.compliance.<code>.
 */
final readonly class ComplianceIssue
{
    public const string Blocking = 'blocking';

    public const string Warning = 'warning';

    public const string Info = 'info';

    /**
     * @param  array<string, scalar|null>  $params
     */
    public function __construct(
        public string $code,
        public string $severity = self::Warning,
        public array $params = [],
        public ?string $envelope = null,
    ) {}

    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function blocking(string $code, array $params = [], ?string $envelope = null): self
    {
        return new self($code, self::Blocking, $params, $envelope);
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function warning(string $code, array $params = [], ?string $envelope = null): self
    {
        return new self($code, self::Warning, $params, $envelope);
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function info(string $code, array $params = []): self
    {
        return new self($code, self::Info, $params);
    }

    public function isBlocking(): bool
    {
        return $this->severity === self::Blocking;
    }

    /**
     * @return array{code: string, severity: string, params: array<string, scalar|null>, envelope: string|null}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'severity' => $this->severity, 'params' => $this->params, 'envelope' => $this->envelope];
    }
}
