<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs;

use Illuminate\Http\UploadedFile;
use Modules\HumanResources\Enums\ApplicationSource;
use Modules\HumanResources\Enums\RecruitingLocale;

/**
 * `SubmitCandidateApplicationAndParseCvCommand`: public careers page application or manual intake by HR.
 */
final readonly class SubmitCandidateApplicationDTO
{
    public function __construct(
        public string $jobOpeningId,
        public string $firstName,
        public string $lastName,
        public string $email,
        public ApplicationSource $source,
        public RecruitingLocale $locale,
        public int $retentionMonths,
        public string $consentTextVersion,
        public ?string $phone = null,
        public ?string $coverLetter = null,
        public ?UploadedFile $cv = null,
        public ?string $actorUserId = null
    ) {}
}
