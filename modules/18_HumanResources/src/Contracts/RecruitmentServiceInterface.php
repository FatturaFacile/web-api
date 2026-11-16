<?php

declare(strict_types=1);

namespace Modules\HumanResources\Contracts;

use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Enums\RecruitingLocale;
use Modules\HumanResources\Models\JobOpening;
use Modules\HumanResources\Models\Position;
use Modules\HumanResources\Models\RecruitingSettings;

/**
 * Recruiting configuration and shared rules of the active tenant: settings, pipeline, email templates and
 * position vacancy.
 */
interface RecruitmentServiceInterface
{
    /**
     * Settings of the active tenant; an unsaved default instance when the tenant never configured recruiting.
     */
    public function settings(): RecruitingSettings;

    /**
     * @param  array{careers?: array<string, mixed>, retention?: array{default_months?: int}, pipeline?: array{stages?: list<array<string, mixed>>}}  $data
     */
    public function updateSettings(array $data): RecruitingSettings;

    public function companyName(?RecruitingSettings $settings = null): string;

    /**
     * Seats of the position still available to openings: headcount budget minus active incumbents minus the
     * unfilled seats reserved by the other open (published or internal only) openings of the position.
     */
    public function availableVacancy(Position $position, ?JobOpening $excluding = null): int;

    /**
     * @return array{stage: string, locale: string, subject: string, body: string, is_enabled: bool, is_default: bool}
     */
    public function emailTemplate(ApplicationStage $stage, RecruitingLocale $locale): array;

    /**
     * Templates of every enabled stage and locale, stored or default.
     *
     * @return list<array{stage: string, locale: string, subject: string, body: string, is_enabled: bool, is_default: bool}>
     */
    public function emailTemplates(?ApplicationStage $stage = null, ?RecruitingLocale $locale = null): array;

    /**
     * @return array{stage: string, locale: string, subject: string, body: string, is_enabled: bool, is_default: bool}
     */
    public function upsertEmailTemplate(ApplicationStage $stage, RecruitingLocale $locale, string $subject, string $body, bool $isEnabled): array;

    /**
     * Position at the end of a Kanban column.
     */
    public function nextBoardPosition(string $jobOpeningId, ApplicationStage $stage): int;
}
