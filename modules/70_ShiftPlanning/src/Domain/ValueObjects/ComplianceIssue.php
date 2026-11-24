<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\ValueObjects;

use Modules\ShiftPlanning\Domain\Enums\IssueSeverity;

/**
 * One finding of the compliance check. The client translates translationKey with params; ruleCode is the stable
 * identifier (for example IT.DAILY_REST_11H) that tests, badges and reports rely on.
 */
final readonly class ComplianceIssue
{
    /**
     * @param  array<string, scalar|null>  $params
     */
    public function __construct(
        public IssueSeverity $severity,
        public string $ruleCode,
        public string $translationKey,
        public array $params = [],
    ) {}

    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function blocking(string $ruleCode, array $params = []): self
    {
        return new self(IssueSeverity::BlockingError, $ruleCode, 'SHIFT_PLANNING.RULE_'.str_replace('.', '_', $ruleCode), $params);
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function warning(string $ruleCode, array $params = []): self
    {
        return new self(IssueSeverity::Warning, $ruleCode, 'SHIFT_PLANNING.RULE_'.str_replace('.', '_', $ruleCode), $params);
    }

    public function isBlocking(): bool
    {
        return $this->severity === IssueSeverity::BlockingError;
    }

    /**
     * @return array{severity: string, rule_code: string, translation_key: string, params: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity->value,
            'rule_code' => $this->ruleCode,
            'translation_key' => $this->translationKey,
            'params' => $this->params,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            IssueSeverity::from((string) $data['severity']),
            (string) $data['rule_code'],
            (string) $data['translation_key'],
            is_array($data['params'] ?? null) ? $data['params'] : [],
        );
    }
}
