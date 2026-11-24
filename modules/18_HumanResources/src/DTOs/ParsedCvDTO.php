<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs;

use Modules\HumanResources\Enums\CvParseStatus;

/**
 * Result of a CV parsing run.
 */
final readonly class ParsedCvDTO
{
    /**
     * @param  list<string>  $skills
     * @param  array{education: list<array{institution: string, degree: string|null, period: string|null}>, experience: list<array{company: string, role: string|null, period: string|null, summary: string|null}>, languages: list<array{language: string, level: string|null}>, skills: list<string>}|null  $profile
     */
    public function __construct(
        public CvParseStatus $status,
        public array $skills = [],
        public ?string $email = null,
        public ?string $phone = null,
        public ?array $profile = null
    ) {}

    public static function failed(): self
    {
        return new self(CvParseStatus::Failed);
    }
}
