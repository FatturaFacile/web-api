<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Domain\Strategy;

/**
 * Outcome of the dimensional threshold check. `messageKey` is a seeded text template key; `params` fill its placeholders.
 */
final readonly class EligibilityResult
{
    /**
     * @param  list<string>  $exceeded  Criteria over the limits of the scheme ("total_assets", "revenue", "average_employees").
     * @param  array<string, string>  $limits
     * @param  list<array{code: string, params: array<string, string>}>  $warnings  Codes are seeded validation message keys.
     * @param  list<string>  $requiredStatements
     */
    public function __construct(
        public string $recommendedStandard,
        public bool $chosenAllowed,
        public array $exceeded,
        public array $limits,
        public string $messageKey,
        public array $warnings,
        public array $requiredStatements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'recommended_standard' => $this->recommendedStandard,
            'chosen_allowed' => $this->chosenAllowed,
            'exceeded' => $this->exceeded,
            'limits' => $this->limits,
            'message_key' => $this->messageKey,
            'warnings' => $this->warnings,
            'required_statements' => $this->requiredStatements,
        ];
    }
}
