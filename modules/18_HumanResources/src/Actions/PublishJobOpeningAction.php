<?php

declare(strict_types=1);

namespace Modules\HumanResources\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\HumanResources\Contracts\RecruitmentServiceInterface;
use Modules\HumanResources\Enums\JobOpeningStatus;
use Modules\HumanResources\Events\JobOpeningPublishedEvent;
use Modules\HumanResources\Exceptions\RecruitingRuleViolationException;
use Modules\HumanResources\Models\JobOpening;
use Modules\HumanResources\Models\Position;

/**
 * `PublishJobOpeningCommand`: publishes an opening on the careers page (or internally only) after checking that
 * every published language is translated and that the position still has vacant headcount.
 */
final readonly class PublishJobOpeningAction
{
    public function __construct(
        private RecruitmentServiceInterface $recruitment,
        private SaveJobOpeningAction $saveJobOpening
    ) {}

    public function execute(JobOpening $opening, bool $internalOnly = false): JobOpening
    {
        $opening = DB::transaction(function () use ($opening, $internalOnly): JobOpening {
            /** @var JobOpening $opening */
            $opening = JobOpening::query()->lockForUpdate()->findOrFail($opening->id);

            if ($opening->status->isClosed()) {
                throw RecruitingRuleViolationException::openingNotPublishable('A closed job opening cannot be published again.');
            }

            try {
                $this->saveJobOpening->assertTranslationsComplete($opening);
            } catch (ValidationException) {
                throw RecruitingRuleViolationException::openingNotPublishable('Every published language needs a title and a description.');
            }

            /** @var Position|null $position */
            $position = Position::query()->lockForUpdate()->find($opening->position_id);
            if ($position === null || $this->recruitment->availableVacancy($position, $opening) < $opening->remainingOpenings()) {
                throw RecruitingRuleViolationException::openingNotPublishable('The position has no vacant headcount for this opening.');
            }

            $opening->status = $internalOnly ? JobOpeningStatus::InternalOnly : JobOpeningStatus::Published;
            $opening->published_at ??= now();
            $opening->save();

            event(new JobOpeningPublishedEvent(
                jobOpeningId: (string) $opening->id,
                reference: (string) $opening->reference,
                positionId: (string) $opening->position_id,
                status: $opening->status->value,
                openingsCount: (int) $opening->openings_count,
                tenantId: (string) $opening->tenant_id,
                companyId: (string) $opening->tenant_id
            ));

            return $opening;
        });

        return $opening;
    }

    /**
     * Close the opening as filled or cancelled.
     */
    public function close(JobOpening $opening, string $outcome): JobOpening
    {
        return DB::transaction(function () use ($opening, $outcome): JobOpening {
            /** @var JobOpening $opening */
            $opening = JobOpening::query()->lockForUpdate()->findOrFail($opening->id);

            if ($opening->status->isClosed()) {
                throw RecruitingRuleViolationException::openingNotOpen();
            }

            $opening->status = $outcome === 'filled' ? JobOpeningStatus::ClosedFilled : JobOpeningStatus::Cancelled;
            $opening->closed_at = now();
            $opening->save();

            return $opening;
        });
    }
}
